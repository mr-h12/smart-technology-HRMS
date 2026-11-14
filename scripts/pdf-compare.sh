#!/usr/bin/env bash
# Compare one page of a reference PDF with one page of a candidate PDF.
# Writes side-by-side.png, overlay.png and anchors.tsv into <outdir>.
# Pages of different sizes (e.g. US Letter vs A4) are normalised by page size:
# the overlay stretches both pages to one canvas and anchors are fractions of each page.
# Needs Poppler (pdftoppm, pdftotext, pdfinfo) and /usr/bin/python3 with PIL.
set -euo pipefail

if [[ $# -lt 3 || $# -gt 4 ]]; then
  echo "usage: $0 <reference.pdf> <candidate.pdf> <outdir> [page=1]" >&2
  exit 2
fi
ref=$1 cand=$2 out=$3 page=${4:-1}
for f in "$ref" "$cand"; do [[ -f $f ]] || { echo "not a file: $f" >&2; exit 2; }; done
mkdir -p "$out"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# 100 dpi keeps true relative sizes for the side-by-side; 1000x1294 is the shared overlay canvas.
for side in ref cand; do
  pdf=${!side}
  pdftoppm -f "$page" -l "$page" -singlefile -r 100 -png "$pdf" "$tmp/$side"
  pdftoppm -f "$page" -l "$page" -singlefile -gray -scale-to-x 1000 -scale-to-y 1294 -png "$pdf" "$tmp/$side-norm"
  pdftotext -f "$page" -l "$page" -bbox "$pdf" "$tmp/$side.html"
  pdfinfo -f "$page" -l "$page" "$pdf" | awk -v p="$page" '$1=="Page" && $2==p && $3=="size:" {print $4, $6, $8}' > "$tmp/$side.size"
done

/usr/bin/python3 -E - "$tmp" "$out" "$ref" "$cand" "$page" <<'PY'
import html, re, sys
from PIL import Image, ImageChops, ImageDraw, ImageFont

tmp, out, ref_path, cand_path, page = sys.argv[1:]
size = {s: open(f'{tmp}/{s}.size').read().split() for s in ('ref', 'cand')}
label = {s: f"{'REFERENCE' if s == 'ref' else 'CANDIDATE'} p{page}  {size[s][0]} x {size[s][1]} pt {' '.join(size[s][2:])}"
         for s in size}
same = size['ref'][:2] == size['cand'][:2]
note = 'same page size' if same else 'page sizes differ: overlay stretched to one canvas, anchors are page fractions'
font = ImageFont.load_default(size=22)

# Side-by-side: same dpi, so physical sizes stay comparable.
imgs = {s: Image.open(f'{tmp}/{s}.png').convert('RGB') for s in ('ref', 'cand')}
w = imgs['ref'].width + imgs['cand'].width + 30
h = max(i.height for i in imgs.values()) + 50
sbs = Image.new('RGB', (w, h), 'white')
d = ImageDraw.Draw(sbs)
x = 0
for s in ('ref', 'cand'):
    sbs.paste(imgs[s], (x, 50))
    d.rectangle([x, 50, x + imgs[s].width - 1, 50 + imgs[s].height - 1], outline='gray')
    d.text((x + 8, 12), label[s], fill='black', font=font)
    x += imgs[s].width + 30
sbs.save(f'{out}/side-by-side.png')

# Overlay: reference-only ink is red, candidate-only ink is blue, shared ink is black.
r = Image.open(f'{tmp}/ref-norm.png').convert('L')
c = Image.open(f'{tmp}/cand-norm.png').convert('L')
ov = Image.merge('RGB', (c, ImageChops.darker(r, c), r))
band = Image.new('RGB', (ov.width, 64), 'white')
bd = ImageDraw.Draw(band)
bd.text((8, 6), 'red = reference only, blue = candidate only, black = both', fill='black', font=font)
bd.text((8, 34), note, fill='black', font=font)
full = Image.new('RGB', (ov.width, ov.height + 64), 'white')
full.paste(band, (0, 0))
full.paste(ov, (0, 64))
full.save(f'{out}/overlay.png')

# Anchors: words that occur exactly once in each PDF. Poppler may return Arabic
# words in visual order, so a word and its reverse count as the same anchor.
def words(side):
    t = open(f'{tmp}/{side}.html', encoding='utf-8').read()
    pw, ph = (float(v) for v in re.search(r'<page width="([\d.]+)" height="([\d.]+)"', t).groups())
    found = {}
    for x0, y0, x1, y1, word in re.findall(
            r'<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)</word>', t):
        word = html.unescape(word)
        if len(word) < 3 or not re.search(r'\w', word) or re.fullmatch(r'[\d\W_]+', word):
            continue
        key = min(word, word[::-1])
        cx, cy = (float(x0) + float(x1)) / 2 / pw, (float(y0) + float(y1)) / 2 / ph
        found.setdefault(key, []).append((word, cx, cy))
    return {k: v[0] for k, v in found.items() if len(v) == 1}

rw, cw = words('ref'), words('cand')
common = sorted(set(rw) & set(cw), key=lambda k: rw[k][2])
step = max(1, len(common) // 12)  # a handful, spread down the page
with open(f'{out}/anchors.tsv', 'w', encoding='utf-8') as f:
    f.write(f'# reference\t{ref_path}\t{label["ref"]}\n# candidate\t{cand_path}\t{label["cand"]}\n')
    f.write(f'# {note}; x, y = word-box centre as a fraction of page width, height; delta = candidate - reference; Arabic words re-reversed from Poppler visual order\n')
    f.write('word\tref_x\tref_y\tcand_x\tcand_y\tdelta_x\tdelta_y\n')
    for k in common[::step]:
        (wd, rx, ry), (_, cx, cy) = rw[k], cw[k]
        wd = wd[::-1] if re.search('[\u0600-\u06ff]', wd) else wd  # back to reading order
        f.write(f'{wd}\t{rx:.4f}\t{ry:.4f}\t{cx:.4f}\t{cy:.4f}\t{cx - rx:+.4f}\t{cy - ry:+.4f}\n')
print(f'{out}: side-by-side.png overlay.png anchors.tsv ({len(common[::step])} of {len(common)} shared words); {note}')
PY
