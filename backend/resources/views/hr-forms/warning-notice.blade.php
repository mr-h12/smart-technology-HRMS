@extends('hr-forms.layout')

@section('form-css')
    body::before { content: ''; position: fixed; inset: 8mm; border: 5px double #141414; }
    main { padding: 18mm 23mm; font-size: 14pt; line-height: 1.65; overflow-wrap: anywhere; }
    h1 { margin-bottom: 5mm; }
    p { margin: 0 0 3mm; }
    .brand { color: #236777; text-align: left; line-height: 1.2; }
    .brand small { color: #141414; display: block; }
    .narrative { margin-top: 5mm; }
    .signatures { margin-top: 8mm; break-inside: avoid; text-align: left; }
    .hr { text-align: right; }
    .line { display: inline-block; width: 49mm; border-bottom: 1px dotted; }
@endsection

@section('document')
@php
    $fields = $snapshot['fields'];
    $date = \App\Support\Localization\ArabicFormDate::format($fields['warning_date']);
@endphp
<p class="brand" dir="ltr" lang="en">Smart Technology <small>For Integrated Systems</small></p>
<h1>نموذج إنذار</h1>
<p>إنه في يوم {{ $date['weekday'] }} الموافق <bdi>{{ $date['date'] }}</bdi></p>
<p>من إدارة شركة سمارت تكنولوجي للأنظمة المتكاملة إلى الموظف / {{ $snapshot['name_ar'] }}</p>
<p>المسمى الوظيفي / {{ $fields['job_title'] }} · القسم / {{ $snapshot['department'] ?? '—' }}
    · رقم الموظف / <bdi>{{ $snapshot['code'] }}</bdi></p>
<div class="narrative">
    <p>الواقعة: {{ $fields['warning_incident'] }}</p>
    <p>التواريخ أو المدة المذكورة: {{ $fields['warning_period'] }}</p>
    <p>بيان التكرار السابق: {{ $fields['warning_prior_occurrence'] }}</p>
    <p>طلب التوضيح: {{ $fields['warning_explanation_request'] }}</p>
    <p>وجب التنبيه والإنذار بعدم التكرار.</p>
</div>
<div class="signatures">
    <p>الاسم / {{ $snapshot['name_ar'] }}</p>
    <p>التوقيع / <span class="line"></span></p>
    <p>توقيع مدير القسم / <span class="line"></span></p>
    <p class="hr">الموارد البشرية / <span class="line"></span></p>
    <p>مدير الشركة / <span class="line"></span></p>
</div>
@endsection
