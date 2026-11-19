@extends('pdf.page')
@php($paper = 'frame')

@section('css')
    /* The bottom padding ends the page 6 pt inside the frame, so a copy that would cross the frame takes a second page and fails the smoke check. */
    main { padding: 47.3pt 63.5pt 34.3pt; font-size: 16pt; line-height: 1.115; }
    p { margin: 15.9pt 0 0; }
    .h { font-size: 18pt; }
    .w { font-size: 16pt; }
    /* A value line's own font size is the value size, so a wrapped line of values takes the smaller pitch; the wording keeps its size. */
    .v { font-size: {{ $valueSize }}pt; }
    .center { text-align: center; }
    .brand { font-family: Carlito; font-size: 20pt; line-height: 1; text-decoration: underline; direction: ltr; text-align: left; }
    /* The company's form is placed on A4 at true size, centred across: the scroll's centre is 8.58 pt right of the page's centre, as the
       company's is, and its middle at the company's height scaled down the page. The title text is centred in the same box. */
    .title { position: relative; left: 8.58pt; width: 250pt; height: 74pt; margin: 7.1pt auto 0; }
    .title svg { position: absolute; top: 4.47pt; left: 0; width: 250pt; height: 74pt; }
    h1 { position: relative; margin: 0; padding-top: 23.7pt; font-size: 28pt; line-height: 1.115; text-align: center; }
    .manager { margin-top: 0; }
    .kinds { position: relative; display: flex; padding-inline: 23.5pt; }
    .kinds > span { flex: none; white-space: nowrap; }
    .kinds > :last-child { margin-inline-start: 12.5pt; }
    /* The company's line has no room for an unpaid leave's fourth kind: it stands at the free left end of the heading's line above. */
    .k-unpaid { position: absolute; left: 0; bottom: calc(100% + 15.9pt); }
    .body { margin-inline: -26pt; line-height: 1.4; text-align: justify; }
    /* The reason's own block is set closer than the paragraph, so the longest copy of each size step keeps a line of room on its page. */
    div.body { line-height: 1.25; }
    .date { white-space: nowrap; }
    .thanks { margin-top: 34.7pt; }
    .hand { margin-top: 15.2pt; text-align: left; }
    .hand p { margin-top: 15.4pt; }
    .hand p:first-child { margin-top: 0; }
    table { width: 478.8pt; margin: 14.9pt -5.4pt 0; border-collapse: collapse; table-layout: fixed; }
    th, td { height: 21.4pt; padding: 0 5.5pt; border: 0.5pt solid; text-align: right; vertical-align: top; overflow-wrap: anywhere; }
    th { width: 203.5pt; font-size: 16pt; }
    .signs { display: grid; grid-template-columns: 1fr 1fr; margin-top: 0.6pt; font-size: 18pt; }
    .signs > div:last-child { text-align: left; padding-left: 4pt; }
    .signs > div:last-child > div:first-child { padding-left: 27pt; }
    /* The company's dotted lines are longer than the same count of Markazi periods: the spacing gives each run the company's pitch and length. */
    .dots { margin-top: 15.8pt; letter-spacing: 1.55pt; }
    .signs img { display: inline-block; width: 40mm; height: 12mm; margin-top: 4pt; object-fit: contain; }
@endsection

@section('document')
@php($reason = $snapshot['reason'] ?? '')
{{-- Inside the 16 pt paragraph a smaller reason's first line would keep the 16 pt pitch. --}}
@php($ownBlock = filled($reason) && $valueSize < 16)
@php($stamp = $signature ? $snapshot['stamp_line'] ?? null : null)
<div class="brand">Smart Technology</div>
<div class="title">
    {{-- Traced from the company's form at 300 dpi, in its US Letter points; the view box is centred on the scroll. --}}
    <svg viewBox="189.58 72 250 74" fill="none" stroke="#000" stroke-width="2.04" stroke-linejoin="round" aria-hidden="true"><path d="M197.28,83.86 C236.64,90.76 273.84,90.49 311.04,84.60 C313.92,83.88 315.36,80.88 318.24,80.16 C355.76,74.77 393.28,73.47 431.88,81.46 L431.88,132.34 C393.28,124.35 355.76,125.65 318.24,131.04 C315.36,131.76 313.92,134.76 311.04,135.48 C273.84,141.37 236.64,141.64 197.28,134.74 Z"/></svg>
    <h1>نموذج طلب إجازة</h1>
</div>
<p class="h manager"><u>مدير الشركة</u></p>
<p class="h center">تحية طيبة وبعد،،،</p>
{{-- The company's form sets the name line at its label's 18 pt. --}}
<p class="{{ $valueSize < 16 ? 'v' : 'h' }}"><span class="h">{{ $feminine ? 'مقدمة لسيادتكم' : 'مقدم لسيادتكم' }} / </span><bdi>{{ $snapshot['name_ar'] }}</bdi></p>
<p class="h center"><u>بيانات منح الطلب</u></p>
<p class="h kinds">
    <x-pdf.choice :width="162.99" :circle="[25.4, 25]" :gap="8" :marked="$snapshot['kind'] === 'annual'">نوع الإجازة (اعتيادية</x-pdf.choice>
    <x-pdf.choice :width="118.32" :circle="[29, 26.2]" :gap="23.7" :marked="$snapshot['kind'] === 'casual'">- عارضة</x-pdf.choice>
    <x-pdf.choice :width="122.4" :circle="[32.2, 25]" :gap="17.4" :marked="$snapshot['kind'] === 'sick'">-مرضي</x-pdf.choice>
    @if ($snapshot['kind'] === 'unpaid')
        <x-pdf.choice class="k-unpaid" :circle="[25.4, 25]" :gap="8" :marked="true">- بدون أجر</x-pdf.choice>
    @endif
    <span>)</span>
</p>
<p class="v body"><span class="w">برجاء من سيادتكم الموافقة على منحي إجازة لمدة ({{ $duration }}) ابتداء من يوم {{ $from['weekday'] }} الموافق <span class="date">{{ $from['date'] }}م</span> إلى يوم {{ $to['weekday'] }} الموافق <span class="date">{{ $to['date'] }}م</span> وذلك لظروف</span> @if (blank($reason))<x-pdf.dots class="w" :count="18" />@elseif (! $ownBlock)<bdi>{{ $snapshot['reason'] }}</bdi>@endif</p>
@if ($ownBlock)<div class="v body"><bdi>{{ $snapshot['reason'] }}</bdi></div>@endif
<p class="center thanks">وتفضلوا سيادتكم بقبول فائق الاحترام،،،</p>
<div class="hand">
    <x-pdf.blank :at="197.36" :label="$feminine ? 'توقيع مقدمة الطلب/' : 'توقيع مقدم الطلب/'" :dots="$feminine ? 20 : 21" />
    <x-pdf.field :at="155.04" label="الوظيفة / " :value="$snapshot['job_title']" :dots="25" />
    <x-pdf.field :at="148.32" label="القسم/" :value="$snapshot['department']" :dots="25" />
    <x-pdf.field :at="183.6" label="طريقة التبليغ/" :value="$snapshot['notification_method']" :dots="25" />
    <x-pdf.blank :at="172.4" label="مدير القسم/" :dots="24" />
</div>
{{-- The substitute's run is plain inline periods, as approved: as an inline-block run the same 20 periods print 11.55 pt shorter. --}}
<table>
    <tr><th>الاستعلام عن رصيد الإجازات</th><td>@if ($balanceDays === null)غير متاح@else الرصيد قبل هذه الإجازة: <bdi>{{ $balanceDays }}</bdi> (سنة <bdi>{{ $snapshot['balance_year'] }}</bdi>)@endif</td></tr>
    <tr><th>رأي مدير القسم</th><td class="v">@if (filled($snapshot['manager_opinion']))<bdi>{{ $snapshot['manager_opinion'] }}</bdi>@endif</td></tr>
    <tr><th>اعتماد مدير القسم</th><td></td></tr>
    <tr><th>توقيع الموظف الذي ينوب العمل</th><td class="v">@if (filled($snapshot['substitute']))<bdi>{{ $snapshot['substitute'] }}</bdi> @endif<x-pdf.dots class="w" :count="20" :pitch="0" /></td></tr>
</table>
<div class="signs">
    <x-pdf.signature data-stamp-line="company_manager" label="توقيع مدير الشركة" :image="$stamp === 'company_manager' ? $signature : null" :dots="23" />
    <x-pdf.signature data-stamp-line="hr" label="توقيع الموارد البشرية" :image="$stamp === 'hr' ? $signature : null" :dots="30" />
</div>
@endsection
