<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>{{ $title }} - الرقم المسلسل {{ $serial }}</title>
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">
<style>
    /* Markazi Text ships as two subsets of one font: the Arabic one has no digits, Latin or ( ) / - . : */
    @font-face { font-family: MarkaziArabic; src: url('{{ $markaziArabic }}') format('woff2'); font-weight: 700; }
    @font-face { font-family: MarkaziLatin; src: url('{{ $markaziLatin }}') format('woff2'); font-weight: 700; }
    @font-face { font-family: Carlito; src: url('{{ $carlito }}') format('truetype'); font-weight: 700; }
    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: MarkaziArabic, MarkaziLatin, Carlito; font-weight: 700; color: #000; }
    bdi { overflow-wrap: anywhere; }
    .serial { position: fixed; bottom: 8pt; left: 26pt; font-size: 7pt; line-height: 1; color: #666; }
@if ($paper === 'frame')
    .paper { position: fixed; inset: 24pt; border: 2.9pt solid; }
    .paper::after { content: ''; position: absolute; inset: 0.9pt; border: 0.5pt solid; }
@elseif ($paper === 'letterhead')
    .letterhead { position: fixed; inset: 0; width: 210mm; height: 297mm; }
    .logo { position: fixed; top: 6mm; left: 14.3mm; width: 98mm; }
    .footer { position: fixed; inset-inline: 0; bottom: 0; width: 210mm; height: 20mm; object-fit: fill; }
    /* The Word file's margins: top 2.79 cm, right 1.0 cm, bottom 2.54 cm, left 1.25 cm. */
    main { position: relative; padding: 79.15pt 28.35pt 72pt 35.45pt; }
    .serial { bottom: calc(20mm + 4pt); left: 35.45pt; }
@endif
    /* A dotted run (x-pdf.dots). Inline-block, because periods inside an Arabic line take the Arabic script and Chromium spaces no Arabic text. */
    .dl { display: inline-block; }
    /* A hand-written row (x-pdf.field, x-pdf.blank): its line is at least --at wide from its left edge, so a short value leaves the label
       where the company's stands, and a longer one pushes it right and wraps as a line of text does. */
    .slot { display: grid; grid-template-columns: fit-content(100%) 1fr; width: max-content; min-width: var(--at); max-width: 100%; margin: 0 auto 0 0; }
    /* The row's dotted run takes no width, so it never pushes its label; its dots run on from the label toward the left edge. */
    .slot .dl { width: 0; white-space: nowrap; }
    /* The leader after a typed value. It takes no width of its own and shows only the whole dots that fit its one line (a dot that does
       not fit wraps to the hidden next line, after the leading zero-width space). Justified, the first dot keeps one distance from the
       value and the last ends at the slot's left edge; the leftover of one pitch spreads between the dots. Left to right, because right
       to left Chromium puts the leftover between the value and its first dot. The negative margin sets the ink gap from a value to its
       first dot at 1.2 to 2.2 pt. The first five dots wrap as one unit, so a run too short to read as a dotted blank does not print. */
    .fill { contain: inline-size; align-self: baseline; height: 1lh; margin-right: -0.6pt; overflow: hidden; direction: ltr; font-size: 16pt; letter-spacing: 1.38pt; line-break: anywhere; text-align: justify; text-justify: inter-character; }
    .fill > span { white-space: nowrap; }
    /* A kind item (x-pdf.choice) ends at its circle, so the circles stand where the company's do whatever the font's widths. */
    .choice { flex: none; white-space: nowrap; text-align: left; }
    /* Chromium rounds a border down to whole CSS pixels: 2.25pt is 3px. */
    .circle { display: inline-block; position: relative; top: 1.3pt; margin-block: -4pt; border: 2.25pt solid; border-radius: 50%; vertical-align: middle; }
    .marked { background: #000; box-shadow: inset 0 0 0 3pt #fff; }
    /* Two fields on one line (x-pdf.pair); the right half's gutter keeps a full value from reading as one word with the left label. */
    .pair { display: grid; text-align: right; }
    @yield('css')
</style>
</head>
<body>
@if ($paper === 'frame')
<div class="paper"></div>
@elseif ($paper === 'letterhead')
<img class="letterhead" src="{{ $letterhead }}" alt="">
<img class="logo" src="{{ $logo }}" alt="">
<img class="footer" src="{{ $footer }}" alt="">
@endif
<main>
@yield('document')
</main>
<div class="serial">الرقم المسلسل <bdi>{{ $serial }}</bdi></div>
</body>
</html>
