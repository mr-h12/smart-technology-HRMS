<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">
<style>
    @font-face { font-family: PlexArabic; src: url('{{ $font }}') format('woff2'); font-weight: 600; }
    @font-face { font-family: PlexArabic; src: url('{{ $boldFont }}') format('woff2'); font-weight: 700; }
    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    /* Avoid ligature glyph mappings that reorder Arabic characters during PDF text extraction. */
    body { margin: 0; font-weight: 600; font-family: PlexArabic, sans-serif; font-feature-settings: "liga" 0, "dlig" 0, "calt" 0, "rlig" 0; color: #141414; }
    .letterhead { position: fixed; inset: 0; width: 210mm; height: 297mm; }
    .footer { position: fixed; inset-inline: 0; bottom: 0; width: 210mm; height: 20mm; background: white; object-fit: fill; }
    main { position: relative; padding: 40mm 20mm 24mm; font-size: 15pt; line-height: 1.65; }
    h1 { font-weight: 700; text-align: center; text-decoration: underline; font-size: 23pt; margin: 0 0 8mm; }
    .serial { font-size: 10pt; text-align: left; }
    .details { width: 100%; table-layout: fixed; }
    .details td { vertical-align: top; overflow-wrap: anywhere; }
    .signature { margin-top: 12mm; margin-right: auto; text-align: center; width: 56mm; break-inside: avoid; }
    .signature img { display: block; width: 100%; height: 18mm; object-fit: contain; }
    .signature-line { display: block; height: 18mm; border-bottom: 1px solid; }
    @yield('form-css')
</style>
</head>
<body>
@unless ($plainPaper ?? false)
<img class="letterhead" src="{{ $letterhead }}" alt="">
<img class="footer" src="{{ $footer }}" alt="">
@endunless
<main>
    <div class="serial">الرقم المسلسل <bdi>{{ $serial }}</bdi></div>
    @yield('document')
</main>
</body>
</html>
