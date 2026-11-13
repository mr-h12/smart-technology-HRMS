@extends('hr-forms.layout')

@section('form-css')
    body::before { content: ''; position: fixed; inset: 8mm; border: 4px double #141414; }
    main { padding: 18mm 22mm; font-size: 14pt; line-height: 1.6; overflow-wrap: anywhere; }
    p { margin: 0 0 4mm; }
    .line { display: inline-block; width: 46mm; border-bottom: 1px dotted; }
    .signatures { margin-top: 9mm; break-inside: avoid; }
@endsection

@section('document')
@php
    $fields = $snapshot['fields'];
    $issued = \App\Support\Localization\ArabicFormDate::format($fields['issued_on']);
    $duration = \App\Support\Localization\ArabicServiceDuration::format(
        (new DateTimeImmutable($fields['hire_date']))->diff(new DateTimeImmutable($fields['last_working_day']))
    ) ?: 'صفر يوم';
    $wage = bcround($fields['last_total_wage'], 2);
    $amount = bcround($fields['final_amount'], 2);
    $hire = \App\Support\Localization\ArabicFormDate::format($fields['hire_date']);
    $last = \App\Support\Localization\ArabicFormDate::format($fields['last_working_day']);
    $wageMonth = substr($fields['wage_month'], 5, 2).'/'.substr($fields['wage_month'], 0, 4);
@endphp
<h1>مكافأة نهاية الخدمة</h1>
<p>إنه في يوم {{ $issued['weekday'] }} الموافق <bdi>{{ $issued['date'] }}</bdi></p>
<p>تؤكد شركة سمارت تكنولوجي للأنظمة المتكاملة بأن الموظف المذكور:</p>
<p>اسم الموظف / {{ $snapshot['name_ar'] }} · رقم الموظف / <bdi>{{ $snapshot['code'] }}</bdi></p>
<p>المسمى الوظيفي / {{ $fields['job_title'] }} · القسم / {{ $snapshot['department'] ?? '—' }}</p>
<p>يعمل في الشركة منذ {{ $duration }}، وهي من {{ $hire['weekday'] }} الموافق <bdi>{{ $hire['date'] }}</bdi>
    إلى {{ $last['weekday'] }} الموافق <bdi>{{ $last['date'] }}</bdi>، ومستحقاته عن كل سنة طبقًا لقانون العمل هي نصف شهر وتحسب على آخر أجر شامل، وكان أجره في شهر <bdi>{{ $wageMonth }}</bdi> هو <bdi>{{ $wage }}</bdi> جنيه.</p>
@if ($fields['submitted_amount'] === null)
    <p><bdi dir="ltr">{{ $fields['completed_years'] }} × ({{ $wage }} ÷ 2) = {{ $amount }}</bdi></p>
@endif
<p>الحساب اليدوي، إن لزم / <span class="line"></span></p>
<p>وبذلك تصبح مكافأة نهاية الخدمة للموظف المذكور هي <bdi>{{ $amount }}</bdi> جنيه.</p>
<p>تحريرًا في يوم <span class="line"></span> الموافق <bdi>____ / ____ / ____</bdi></p>
<div class="signatures">
    <p>توقيع الموظف / <span class="line"></span></p>
    <p>الرقم القومي / <span class="line"></span></p>
    <p>مدير الشركة / <span class="line"></span></p>
</div>
@endsection
