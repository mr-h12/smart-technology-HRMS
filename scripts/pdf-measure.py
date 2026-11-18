#!/usr/bin/python3
"""True-point measurement of one-page form PDFs (Poppler + /usr/bin/python3's Pillow; nothing is stretched).

  measure.py table PDF
      page size, page count, fonts; every text word, image and vector shape with its box in PDF points
      (x from the left edge, cx = box centre from the page's vertical centre line, y from the top);
      the white between neighbouring words on each row.
  measure.py compare BASELINE CANDIDATE [--out DIR] [--tol 0.1] [--yscale 1] [--dpi 100]
      page size and count, fonts, each element's shift, changed white between neighbours, raster diff.
      x is compared from each page's centre line, y from each page's top; --yscale multiplies the baseline's
      y (only for the US Letter company form, which the approved build placed at y * 841.92 / 792).
      Exit 0 when nothing differs beyond --tol and no pixel differs, else 1.
  measure.py check FORM PDF
      the approved layout rules a copy with no approved twin must keep (FORM is leave-request or experience-certificate):
      no two words of a row closer than 1 pt, except a value and its leader; on the leave request, after each typed
      value on a hand-written row no dot or at least five, the last dot's box edge on the rows' left edge and 0.7 to 0.9 pt
      of box white from the value to its first dot; on the certificate, at least 7 pt of white before each left label.
      Exit 0 when every rule holds, else 1.

Words are matched by their sorted characters after NFKC, so Poppler's reversed Markazi ligatures
(«أدنى» extracted as «أدىن») and presentation forms match; repeats of one word pair up nearest first.
Word boxes are Poppler's glyph boxes (advance width x ascent/descent), not ink."""
import argparse, os, re, subprocess, sys, tempfile, unicodedata

MARKS = dict.fromkeys(map(ord, '​‌‍‎‏‪‫‬‭‮⁦⁧⁨⁩'))
ARABIC = re.compile('[؀-ۿﭐ-﷿ﹰ-﻿]')


def run(*cmd):
    return subprocess.run(cmd, capture_output=True, text=True, check=True).stdout


def info(pdf):
    out = run('pdfinfo', pdf)
    w, h = map(float, re.search(r'Page size:\s+([\d.]+) x ([\d.]+)', out).groups())
    fonts = []
    for line in run('pdffonts', pdf).splitlines()[2:]:
        p = line.split()
        fonts.append((re.sub(r'^[A-Z]{6}\+', '', p[0]), ' '.join(p[1:-6]), p[-5]))
    return {'w': w, 'h': h, 'pages': int(re.search(r'Pages:\s+(\d+)', out).group(1)), 'fonts': sorted(set(fonts))}


def words(pdf):
    t = run('pdftotext', '-f', '1', '-l', '1', '-bbox', pdf, '-')
    out = []
    for x0, y0, x1, y1, w in re.findall(r'<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)</word>', t):
        w = w.replace('&amp;', '&').replace('&lt;', '<').replace('&gt;', '>').replace('&quot;', '"').translate(MARKS)
        if not w.strip():
            continue
        # pdftotext -bbox gives visual order: an Arabic word is reversed to read right to left, its digit and Latin runs back again.
        shown = re.sub(r'[0-9A-Za-z]+', lambda m: m.group(0)[::-1], w[::-1]) if ARABIC.search(w) else w
        out.append({'kind': 'word', 'text': shown, 'key': ''.join(sorted(unicodedata.normalize('NFKC', w))),
                    'box': tuple(map(float, (x0, y0, x1, y1)))})
    return out


def matrix(s):
    return list(map(float, re.split(r'[,\s]+', s.strip()))) if s else [1, 0, 0, 1, 0, 0]


def graphics(pdf):
    """Images and vector shapes from Poppler's SVG export (cairo writes every placement as a matrix on the element)."""
    with tempfile.TemporaryDirectory() as d:
        subprocess.run(['pdftocairo', '-svg', '-f', '1', '-l', '1', pdf, d + '/p.svg'], check=True)
        s = open(d + '/p.svg', encoding='utf8').read()
    if re.search(r'<g\b[^>]*transform=', s):
        print('WARNING: a group carries a transform; image and shape boxes ignore it', file=sys.stderr)
    sizes = {i: (float(w), float(h)) for i, w, h in re.findall(r'<image id="([^"]+)"[^>]*?width="([\d.]+)" height="([\d.]+)"', s)}
    body = re.sub(r'<(defs|clipPath|mask|symbol)\b.*?</\1>', '', s, flags=re.S)
    out = []
    for ref, m in re.findall(r'<use xlink:href="#([^"]+)"[^>]*?transform="matrix\(([^)]*)\)"', body):
        if ref in sizes:
            a, b, c, dd, e, f = matrix(m)
            w, h = sizes[ref]
            out.append({'kind': 'image', 'text': '%gx%gpx' % (w, h), 'key': 'image', 'box': (e, f, e + a * w, f + dd * h)})
    for p in re.findall(r'<path\b[^>]*>', body):
        a, b, c, dd, e, f = matrix((re.search(r'transform="matrix\(([^)]*)\)"', p) or [None, ''])[1])
        nums = list(map(float, re.findall(r'-?[\d.]+(?:e-?\d+)?', re.search(r' d="([^"]*)"', p).group(1))))
        pts = [(a * x + c * y + e, b * x + dd * y + f) for x, y in zip(nums[0::2], nums[1::2])]
        xs, ys = [q[0] for q in pts], [q[1] for q in pts]
        sw = re.search(r'stroke-width="([\d.]+)"', p)
        kind = 'stroke %.2fpt' % (float(sw.group(1)) * a) if sw and 'stroke="' in p else 'fill'
        out.append({'kind': 'shape', 'text': kind, 'key': kind, 'box': (min(xs), min(ys), max(xs), max(ys))})
    return out


def elements(pdf):
    return words(pdf) + graphics(pdf)


def rows(ws):
    """Words grouped into visual rows (vertical overlap over half the smaller height), each row sorted left to right."""
    out = []
    for w in sorted(ws, key=lambda w: (w['box'][1] + w['box'][3]) / 2):
        y0, y1 = w['box'][1], w['box'][3]
        for r in out:
            ov = min(y1, r['y1']) - max(y0, r['y0'])
            if ov > 0.5 * min(y1 - y0, r['y1'] - r['y0']):
                r['w'].append(w); r['y0'], r['y1'] = min(r['y0'], y0), max(r['y1'], y1)
                break
        else:
            out.append({'y0': y0, 'y1': y1, 'w': [w]})
    for r in out:
        r['w'].sort(key=lambda w: w['box'][0])
    return out


def fmt(e, cx):
    x0, y0, x1, y1 = e['box']
    return '%8.2f %8.2f %8.2f %8.2f %7.2f %7.2f %8.2f' % (x0, y0, x1, y1, x1 - x0, y1 - y0, (x0 + x1) / 2 - cx)


def table(pdf):
    i = info(pdf)
    print('file   %s\npage   %.2f x %.2f pt, %d page(s)\nfonts  %s' % (pdf, i['w'], i['h'], i['pages'], '; '.join('%s (%s) emb=%s' % f for f in i['fonts'])))
    cx = i['w'] / 2
    ws = words(pdf)
    print('\nTEXT (row, word; x0 y0 x1 y1 w h in pt; cx = centre from the page centre line; text read right to left, Markazi ligature pairs may stand swapped)')
    print('%-7s %8s %8s %8s %8s %7s %7s %8s  %s' % ('row.w', 'x0', 'y0', 'x1', 'y1', 'w', 'h', 'cx', 'text'))
    for n, r in enumerate(rows(ws), 1):
        for k, w in enumerate(r['w'], 1):
            print('%-7s %s  %s' % ('%d.%d' % (n, k), fmt(w, cx), w['text']))
        gaps = ['%.2f' % (b['box'][0] - a['box'][2]) for a, b in zip(r['w'], r['w'][1:])]
        if gaps:
            print('        white between neighbours, left to right: %s' % ' | '.join(gaps))
    print('\nIMAGES AND SHAPES (pt)')
    for e in graphics(pdf):
        print('%-22s %s' % ('%s %s' % (e['kind'], e['text']), fmt(e, cx)))


def pair(a, b, keyed=True, limit=None):
    """Greedy nearest-first pairing of a and b (same key when keyed); returns pairs and the leftovers."""
    c = lambda e: ((e['box'][0] + e['box'][2]) / 2, (e['box'][1] + e['box'][3]) / 2)
    cand = []
    for i, u in enumerate(a):
        for j, v in enumerate(b):
            if keyed and u['key'] != v['key']:
                continue
            (ux, uy), (vx, vy) = c(u), c(v)
            d = ((ux - vx) ** 2 + (uy - vy) ** 2) ** 0.5
            if limit is None or d <= limit:
                cand.append((d, i, j))
    used_a, used_b, out = set(), set(), []
    for d, i, j in sorted(cand):
        if i not in used_a and j not in used_b:
            used_a.add(i); used_b.add(j); out.append((a[i], b[j]))
    return out, [u for i, u in enumerate(a) if i not in used_a], [v for j, v in enumerate(b) if j not in used_b]


def compare(base, cand, out, tol, yscale, dpi):
    ib, ic = info(base), info(cand)
    diffs = 0
    print('baseline   %s\ncandidate  %s\ntolerance  %.2f pt; x from each page centre line, y from each top%s\n' % (
        base, cand, tol, '' if yscale == 1 else '; baseline y x %.6f' % yscale))
    same = (abs(ib['w'] - ic['w']) < 0.01, abs(ib['h'] - ic['h']) < 0.01, ib['pages'] == ic['pages'], ib['fonts'] == ic['fonts'])
    diffs += same.count(False)
    print('page size  %.2f x %.2f  ->  %.2f x %.2f  %s' % (ib['w'], ib['h'], ic['w'], ic['h'], 'same' if all(same[:2]) else 'DIFFERENT'))
    print('pages      %d -> %d  %s' % (ib['pages'], ic['pages'], 'same' if same[2] else 'DIFFERENT'))
    print('fonts      %s\n        -> %s  %s' % ('; '.join('%s (%s) emb=%s' % f for f in ib['fonts']), '; '.join('%s (%s) emb=%s' % f for f in ic['fonts']), 'same' if same[3] else 'DIFFERENT'))

    def norm(es, i, ys):
        for e in es:
            x0, y0, x1, y1 = e['box']
            e['box'] = (x0 - i['w'] / 2, y0 * ys, x1 - i['w'] / 2, y1 * ys)
        return es
    eb, ec = norm(elements(base), ib, yscale), norm(elements(cand), ic, 1)
    pairs, ob, oc = pair(eb, ec)
    changed, ob, oc = pair([e for e in ob if e['kind'] == 'word'], [e for e in oc if e['kind'] == 'word'], keyed=False, limit=40)
    ob += [e for e in eb if e['kind'] != 'word' and all(e is not p[0] for p in pairs)]
    oc += [e for e in ec if e['kind'] != 'word' and all(e is not p[1] for p in pairs)]
    ob = [e for e in ob if all(e is not p[0] for p in changed)]
    oc = [e for e in oc if all(e is not p[1] for p in changed)]
    rowsout = []
    moved = []
    for u, v in pairs + changed:
        d = [v['box'][k] - u['box'][k] for k in range(4)]
        dw, dh = d[2] - d[0], d[3] - d[1]
        rowsout.append((u, v, d))
        if max(abs(d[0]), abs(d[1]), abs(dw), abs(dh)) > tol:
            moved.append((max(abs(d[0]), abs(d[1]), abs(dw), abs(dh)), u, v, d))
    hdr = '%-6s %-28s %8s %8s %8s %7s  %s' % ('kind', 'text (baseline -> candidate)', 'dx left', 'dx right', 'dy top', 'dh', 'baseline box x0 y0 x1 y1 (x from centre)')
    label = lambda u, v: u['text'] if u['text'] == v['text'] else '%s -> %s' % (u['text'], v['text'])
    line = lambda u, v, d: '%-6s %-28s %+8.2f %+8.2f %+8.2f %+7.2f  %s' % (u['kind'], label(u, v)[:60], d[0], d[2], d[1], d[3] - d[1], ' '.join('%.2f' % q for q in u['box']))
    diffs += len(moved) + len(ob) + len(oc) + sum(1 for u, v in changed)
    print('\nELEMENTS  %d paired (%d with changed text), %d moved or resized beyond %.2f pt, %d only in baseline, %d only in candidate' % (
        len(pairs) + len(changed), len(changed), len(moved), tol, len(ob), len(oc)))
    if moved:
        print(hdr)
        for _, u, v, d in sorted(moved, key=lambda m: -m[0]):
            print(line(u, v, d))
    changed_only = [(u, v) for u, v in changed if not any(u is m[1] for m in moved)]
    if changed_only:
        print('changed text, same place within tolerance:')
        for u, v in changed_only:
            print('  %s  at %s' % (label(u, v), ' '.join('%.2f' % q for q in u['box'])))
    for name, es in (('only in baseline', ob), ('only in candidate', oc)):
        for e in es:
            print('%-18s %-6s %-28s %s' % (name, e['kind'], e['text'], ' '.join('%.2f' % q for q in e['box'])))

    # White between neighbouring words of a baseline row, measured again between the same two words in the candidate.
    to_c = {id(u): v for u, v in pairs + changed}
    gap_diffs = []
    for r in rows([e for e in eb if e['kind'] == 'word']):
        for a, b in zip(r['w'], r['w'][1:]):
            if id(a) in to_c and id(b) in to_c:
                g0 = b['box'][0] - a['box'][2]
                g1 = to_c[id(b)]['box'][0] - to_c[id(a)]['box'][2]
                if abs(g1 - g0) > tol:
                    gap_diffs.append((a, b, g0, g1))
    to_b = {id(v): u for u, v in pairs + changed}
    close = lambda es: [(a, b, b['box'][0] - a['box'][2]) for r in rows([e for e in es if e['kind'] == 'word']) for a, b in zip(r['w'], r['w'][1:]) if b['box'][0] - a['box'][2] < 1.0]
    close_b = {(id(a), id(b)) for a, b, g in close(eb)}
    touching = [(a, b, g, (id(to_b.get(id(a))), id(to_b.get(id(b)))) in close_b) for a, b, g in close(ec)]
    diffs += len(gap_diffs) + sum(1 for t in touching if not t[3])
    print('\nWHITE BETWEEN NEIGHBOURS  %d changed beyond %.2f pt; neighbours closer than 1.00 pt in the candidate: %d new, %d as in the baseline' % (
        len(gap_diffs), tol, sum(1 for t in touching if not t[3]), sum(1 for t in touching if t[3])))
    for a, b, g0, g1 in gap_diffs:
        print('  %s | %s : %.2f -> %.2f (%+.2f)' % (a['text'], b['text'], g0, g1, g1 - g0))
    for a, b, g, old in touching:
        print('  %s: %s | %s : %.2f at y %.2f' % ('as baseline' if old else 'NEW close', a['text'], b['text'], g, a['box'][1]))

    if out:
        os.makedirs(out, exist_ok=True)
        with open(os.path.join(out, 'elements.tsv'), 'w', encoding='utf8') as f:
            f.write('kind\tbaseline text\tcandidate text\tdx left\tdx right\tdy top\tdh\tbx0\tby0\tbx1\tby1 (x from the page centre)\n')
            for u, v, d in rowsout:
                f.write('%s\t%s\t%s\t%.2f\t%.2f\t%.2f\t%.2f\t%s\n' % (u['kind'], u['text'], v['text'], d[0], d[2], d[1], d[3] - d[1], '\t'.join('%.2f' % q for q in u['box'])))
    try:
        from PIL import Image, ImageChops
    except ImportError:
        print('\nRASTER  not run: Pillow is not importable from %s' % sys.executable)
        return diffs
    with tempfile.TemporaryDirectory() as d:
        for n, p in (('b', base), ('c', cand)):
            subprocess.run(['pdftoppm', '-f', '1', '-l', '1', '-r', str(dpi), '-gray', '-png', '-singlefile', p, '%s/%s' % (d, n)], check=True)
        A, C = Image.open(d + '/b.png').convert('L'), Image.open(d + '/c.png').convert('L')
    W, H = max(A.width, C.width), max(A.height, C.height)
    pad = lambda im: (lambda c: (c.paste(im, ((W - im.width) // 2, 0)), c)[1])(Image.new('L', (W, H), 255))
    A, C = pad(A), pad(C)
    delta = ImageChops.difference(A, C)
    hist = delta.histogram()
    changed_px, ink_px = sum(hist[1:]), sum(hist[33:])
    diffs += changed_px > 0
    print('\nRASTER  %d dpi (%.4f pt per pixel), pages aligned on the centre line and the top%s: %d pixels differ, %d by more than 32/255' % (
        dpi, 72 / dpi, '' if (A.size == C.size and ib['w'] == ic['w']) else ' (sizes differ; no stretch)', changed_px, ink_px))
    if out:
        Image.merge('RGB', (C, A, ImageChops.darker(A, C))).save(os.path.join(out, 'diff.png'))
        print('        %s: black = both, red = baseline only, green = candidate only' % os.path.join(out, 'diff.png'))
        bbox = delta.point(lambda v: 255 if v > 32 else 0).getbbox()
        if bbox:
            s = 72 / dpi
            print('        changed area x %.1f..%.1f y %.1f..%.1f pt (x from the left of the wider page)' % (bbox[0] * s, bbox[2] * s, bbox[1] * s, bbox[3] * s))
    return diffs


# The approved renders' numbers (leave on 55aebe3, certificate on 1c247fd), in true points from the page's left edge.
LEAVE_LEFT_EDGE = 64.00
LEAVE_VALUE_LABELS = ('الوظيفة', 'القسم/', 'التبليغ/')
LEAVE_MIN_DOTS = 5
LEAVE_VALUE_WHITE = (0.7, 0.9)
CERT_LEFT_LABEL_EDGE = 240.56
CERT_GUTTER = 7.0
CERT_PAIR_END = ('بأنه', 'بأنها')
MIN_WHITE = 1.0


MARK = re.compile('[ً-ْٰ]')


def neighbours(r):
    """Each word and the nearest word to its right on its own line: a row of rows() can hold two lines of a smaller size."""
    line = lambda a, b: min(a['box'][3], b['box'][3]) - max(a['box'][1], b['box'][1]) > 0.5 * min(a['box'][3] - a['box'][1], b['box'][3] - b['box'][1])
    for a in r['w']:
        right = [b for b in r['w'] if b is not a and b['box'][0] + b['box'][2] > a['box'][0] + a['box'][2] and line(a, b)]
        if right:
            yield a, min(right, key=lambda b: b['box'][0])


def check(form, pdf):
    dots = lambda w: set(w['text']) == {'.'}
    found = []
    rs = rows(words(pdf))
    for r in rs:
        for a, b in neighbours(r):
            g = b['box'][0] - a['box'][2]
            # Poppler boxes a word with an Arabic mark (the tanween of «متميزًا») past its ink, so the approved certificate reads 0.87 pt there.
            if g < MIN_WHITE and dots(a) == dots(b) and not MARK.search(a['text'] + b['text']):
                found.append('%s | %s : %.2f pt apart at y %.2f (under %.2f)' % (a['text'], b['text'], g, a['box'][1], MIN_WHITE))
    if form == 'leave-request':
        for r in rs:
            label = next((w for w in r['w'] if w['text'].rstrip('.') in LEAVE_VALUE_LABELS), None)
            # A row with no value (an empty field, the «مدير القسم/» signature) prints its dots straight after the label, and Poppler
            # extracts both as one word: no rule after a value applies, and the joined box's left edge (62.81 to 63.71) is no dot's.
            if label is None or label['text'] not in LEAVE_VALUE_LABELS:
                continue
            left = [w for w in r['w'] if w['box'][2] <= label['box'][0]]
            run, value = [w for w in left if dots(w)], [w for w in left if not dots(w)]
            n = sum(len(w['text']) for w in run)
            if not n:
                continue
            at = '%s row at y %.2f' % (label['text'], label['box'][1])
            if n < LEAVE_MIN_DOTS:
                found.append('%s: %d dot(s) after the value (none or at least %d)' % (at, n, LEAVE_MIN_DOTS))
            edge = min(w['box'][0] for w in run)
            if abs(edge - LEAVE_LEFT_EDGE) > 0.1:
                found.append('%s: last dot at x %.2f, not the rows\' left edge %.2f' % (at, edge, LEAVE_LEFT_EDGE))
            if value:
                white = min(w['box'][0] for w in value) - max(w['box'][2] for w in run)
                if not LEAVE_VALUE_WHITE[0] <= white <= LEAVE_VALUE_WHITE[1]:
                    found.append('%s: %.2f pt from the value to its first dot (%.1f to %.1f)' % (at, white, *LEAVE_VALUE_WHITE))
    elif form == 'experience-certificate':
        start = next((i for i, r in enumerate(rs) if any(w['text'] == 'المسمى' for w in r['w'])), None)
        if start is None:
            found.append('no «المسمى» row')
        for r in rs[start:] if start is not None else []:
            if r['w'][-1]['text'] in CERT_PAIR_END:
                break
            left = [w for w in r['w'] if w['box'][2] <= CERT_LEFT_LABEL_EDGE + 0.1]
            right = [w for w in r['w'] if w['box'][2] > CERT_LEFT_LABEL_EDGE + 0.1]
            if left and right:
                white = min(w['box'][0] for w in right) - max(w['box'][2] for w in left)
                if white < CERT_GUTTER:
                    found.append('row at y %.2f: %.2f pt between the halves (at least %.1f)' % (r['y0'], white, CERT_GUTTER))
    else:
        raise SystemExit('check takes leave-request or experience-certificate')
    print('check %s %s' % (form, pdf))
    for f in found:
        print('  ' + f)
    return len(found)


if __name__ == '__main__':
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('mode', choices=['table', 'compare', 'check'])
    ap.add_argument('pdfs', nargs='+')
    ap.add_argument('--out')
    ap.add_argument('--tol', type=float, default=0.1)
    ap.add_argument('--yscale', type=float, default=1.0)
    ap.add_argument('--dpi', type=int, default=100)
    a = ap.parse_args()
    if a.mode == 'table':
        for p in a.pdfs:
            table(p)
    elif a.mode == 'check':
        if len(a.pdfs) != 2:
            ap.error('check takes a form and a PDF')
        n = check(*a.pdfs)
        print('RESULT  %s' % ('every rule holds' if n == 0 else '%d finding(s)' % n))
        sys.exit(1 if n else 0)
    else:
        if len(a.pdfs) != 2:
            ap.error('compare takes a baseline and a candidate')
        n = compare(a.pdfs[0], a.pdfs[1], a.out, a.tol, a.yscale, a.dpi)
        print('\nRESULT  %s' % ('identical within %.2f pt and 0 pixels' % a.tol if n == 0 else '%d difference(s)' % n))
        sys.exit(1 if n else 0)
