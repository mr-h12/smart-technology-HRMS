@extends('hr-forms.layout')

@section('form-css')
    body::before { content: ''; position: fixed; inset: 8mm; border: 5px double #141414; }
    main { padding: 18mm 24mm; font-size: 15pt; line-height: 1.65; overflow-wrap: anywhere; }
    h1 { margin-bottom: 5mm; }
    h2 { margin: 4mm 0; text-align: center; font-size: 17pt; text-decoration: underline; }
    p { margin: 0 0 4mm; }
    .brand { text-align: left; color: #487fbd; text-decoration: underline; }
    .greeting { text-align: center; }
    .applicant, .company { text-align: left; }
    .approvals { margin-top: 7mm; break-inside: avoid; }
    .line { display: inline-block; width: 48mm; border-bottom: 1px dotted; }
@endsection

@section('document')
@php
    $fields = $snapshot['fields'];
    $requested = new DateTimeImmutable('1970-01-01 '.$fields['requested_end']);
    $regular = new DateTimeImmutable('1970-01-01 '.$fields['regular_end']);
    $interval = $requested->diff($regular);
    $duration = \App\Support\Localization\ArabicServiceDuration::hoursAndMinutes($interval->h * 60 + $interval->i);
    $issued = \App\Support\Localization\ArabicFormDate::format($fields['issued_on']);
@endphp
<p class="brand" dir="ltr" lang="en">Smart Technology</p>
<h1>طلب انصراف باكرًا</h1>
<p>مقدم إلى سيادتكم / {{ $snapshot['name_ar'] }}<br>
    قسم / {{ $snapshot['department'] ?? '—' }} · رقم الموظف / <bdi>{{ $snapshot['code'] }}</bdi>
</p>
<p>إلى مدير الشركة سمارت تكنولوجي للأنظمة المتكاملة وقسم الموارد البشرية</p>
<p class="greeting">تحية طيبة وبعد،،،</p>
<h2>الموضوع</h2>
<p>أتقدم إلى سيادتكم بطلب الانصراف باكرًا عن مواعيد العمل الرسمية لمدة {{ $duration }}،
    علمًا بأن مواعيد العمل من الساعة <bdi>{{ $fields['regular_start'] }}</bdi> إلى الساعة <bdi>{{ $fields['regular_end'] }}</bdi>،
    وأطلب من سيادتكم أن تكون مواعيدي الرسمية من الساعة <bdi>{{ $fields['regular_start'] }}</bdi>
    إلى الساعة <bdi>{{ $fields['requested_end'] }}</bdi>.
</p>
<p>وتفضلوا سيادتكم بقبول فائق الاحترام والتقدير.</p>
<p>تحريرًا في يوم {{ $issued['weekday'] }} الموافق <bdi>{{ $issued['date'] }}</bdi></p>
<div class="applicant">
    <p>مقدم لسيادتكم / {{ $snapshot['name_ar'] }}</p>
    <p>التوقيع / <span class="line"></span></p>
</div>
<div class="approvals">
    <p>توقيع الموارد البشرية / <span class="line"></span></p>
    <p class="company">توقيع مدير الشركة / <span class="line"></span></p>
</div>
@endsection
