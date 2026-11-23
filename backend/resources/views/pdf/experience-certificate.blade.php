@extends('pdf.page')
@php($paper = 'letterhead')

@section('css')
    /* The Word file's «single» line is the font's 1.15 em; its paragraphs end 8 pt apart. */
    main { font-size: 15pt; line-height: 1.24; }
    p { margin: 0 0 8pt; }
    h1 { margin: 27.7pt 0 8pt; font-size: 18pt; line-height: 1.24; text-align: center; text-decoration: underline; }
    .day { margin: 0; font-size: 18pt; }
    .body { line-height: {{ 1.15 * $lineSpacing }}; }
    /* Only the paragraphs: a pair keeps the shell's right alignment, so a wrapped value is not spread across its half. */
    p.body { text-align: justify; }
    /* A line of values takes the value size, so a wrapped value takes the smaller pitch; the wording keeps its 15 pt. */
    .v { font-size: {{ $valueSize }}pt; }
    .w { font-size: 15pt; }
    .date { white-space: nowrap; }
    /* Keeps «الشركة.» from standing alone on ف٧'s last line. */
    .closing { text-wrap: pretty; }
    /* «مدير الشركة» stands at the far left whether or not the copy is signed; the signature starts under it. */
    .sign { margin-top: 13.9pt; font-size: 17pt; line-height: 1.725; text-align: left; }
    .sign img { display: block; width: 40mm; height: 12mm; margin-right: auto; object-fit: contain; object-position: left; }
@endsection

@section('document')
<h1>شهـــادة خبرة</h1>
<p class="day">اليوم:{{ $issuedOn['weekday'] }} التاريخ: <span class="date">{{ $issuedOn['date'] }}</span></p>
<p class="body">هذه الشهادة مقدمة من شركة/ سمارت تكنولوجي للأنظمة المتكاملة بأن {{ $feminine ? 'الموظفة المذكورة بياناتها أدناه' : 'الموظف المذكورة بياناته أدناه' }}</p>
{{-- The second half starts where the Word file's spaces put it. --}}
<x-pdf.pair class="body v" :split="327.1">
    <x-slot:right><span class="w">{{ $feminine ? 'اسم الموظفة/' : 'اسم الموظف/' }} </span><bdi>{{ $snapshot['name_ar'] }}</bdi></x-slot:right>
    <x-slot:left><span class="w">المسمى الوظيفي/ </span><bdi>{{ $snapshot['job_title'] }}</bdi></x-slot:left>
</x-pdf.pair>
<x-pdf.pair class="body v" :split="327.1">
    <x-slot:right><span class="w">بطاقة رقم قومي/ </span><bdi>{{ $snapshot['national_id'] }}</bdi></x-slot:right>
    <x-slot:left><span class="w">قسم/ </span><bdi>{{ $snapshot['department'] }}</bdi></x-slot:left>
</x-pdf.pair>
<p class="body">{{ $feminine ? 'بأنها انضمت' : 'بأنه انضم' }} للعمل بوظيفة <span class="v"><bdi>{{ $snapshot['job_title'] }}</bdi></span> ضمن فريق العمل في الشركة لدى فترة زمنية تجاوزت {{ $service }} {{ $feminine
    ? 'وهي من أكفاء الموظفين الذين قد مروا على الشركة حيث كانت متميزة في أداء جميع مهامها الوظيفية وكانت تتمتع'
    : 'وهو من أكفاء الموظفين الذين قد مروا على الشركة حيث كان متميزاً في أداء جميع مهامه الوظيفية وكان يتمتع' }} بالتقدير والاحترام والثقة الكاملة من جميع المسئولين لدى الشركة.</p>
<p class="body closing">تم تحرير هذه الشهادة في يوم {{ $issuedOn['weekday'] }} الموافق <span class="date">{{ $issuedOn['date'] }}</span> بناء على رغبة {{ $feminine ? 'الموظفة' : 'الموظف' }} دون أدنى مسئولية من الشركة.</p>
<x-pdf.signature class="sign" label="مدير الشركة" :image="$signature" />
@endsection
