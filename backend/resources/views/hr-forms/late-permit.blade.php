@extends('hr-forms.layout')

@section('form-css')
    body::before { content: ''; position: fixed; inset: 8mm; border: 4px double #141414; }
    main { padding: 18mm 24mm; font-size: 15pt; line-height: 1.55; overflow-wrap: anywhere; }
    h1 { margin: 0 0 2mm; }
    p { margin: 0 0 4mm; }
    .courtesy { text-align: center; }
    .applicant, .company { width: 55%; margin-inline-start: auto; text-align: left; }
    .approvals { margin-top: 13mm; break-inside: avoid; }
    .line { display: inline-block; width: 39mm; border-bottom: 1px dotted; }
@endsection

@section('document')
@php
    $fields = $snapshot['fields'];
    $start = new DateTimeImmutable('1970-01-01 '.$fields['start_time']);
    $end = new DateTimeImmutable('1970-01-01 '.$fields['end_time']);
    $interval = $start->diff($end);
    $duration = \App\Support\Localization\ArabicServiceDuration::hoursAndMinutes($interval->h * 60 + $interval->i);
    $permit = \App\Support\Localization\ArabicFormDate::format($fields['permit_date']);
    $issued = \App\Support\Localization\ArabicFormDate::format($fields['issued_on']);
@endphp
<h1>إذن تأخير</h1>
<p>مقدم لسيادتكم / {{ $fields['addressee'] }}</p>
<p>أرجو من سيادتكم التكرم والموافقة على منحي إذن تأخير عن العمل لمدة {{ $duration }}، وذلك خلال يوم
    {{ $permit['weekday'] }} الموافق <bdi>{{ $permit['date'] }}</bdi> من الساعة
    <bdi>{{ $fields['start_time'] }}</bdi> إلى الساعة <bdi>{{ $fields['end_time'] }}</bdi> وذلك للأسباب التالية:
    {{ $fields['reason'] }}
</p>
<p class="courtesy">وتفضلوا سيادتكم بقبول فائق الاحترام والتقدير،،،</p>
<div class="applicant">
    <p>{{ $feminine ? 'مقدمة' : 'مقدم' }} لسيادتكم</p>
    <p>الاسم / {{ $snapshot['name_ar'] }}</p>
    <p>رقم {{ $feminine ? 'الموظفة' : 'الموظف' }} / <bdi>{{ $snapshot['code'] }}</bdi> · القسم / {{ $snapshot['department'] ?? '—' }}</p>
    <p>التوقيع / <span class="line"></span></p>
    <p>الوظيفة / {{ $fields['job_title'] }}</p>
    <p>المدير المسؤول / {{ $fields['responsible_manager'] }}</p>
</div>
<p>تحريرًا في يوم {{ $issued['weekday'] }} الموافق <bdi>{{ $issued['date'] }}</bdi></p>
<div class="approvals">
    <p>الموارد البشرية / <span class="line"></span></p>
    <p class="company">مدير الشركة / <span class="line"></span></p>
</div>
@endsection
