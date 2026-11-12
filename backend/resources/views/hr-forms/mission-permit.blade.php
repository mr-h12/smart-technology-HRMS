@extends('hr-forms.layout')

@section('form-css')
    body::before { content: ''; position: fixed; inset: 8mm; border: 5px double #141414; }
    main { padding: 23mm 26mm; font-size: 15pt; line-height: 1.6; overflow-wrap: anywhere; }
    h1 { margin-bottom: 12mm; }
    p { margin: 0 0 9mm; }
    .reason { white-space: pre-wrap; }
    .approvals { margin-top: 12mm; break-inside: avoid; }
    .company { text-align: left; }
    .line { display: inline-block; width: 46mm; border-bottom: 1px dotted; }
@endsection

@section('document')
@php
    $fields = $snapshot['fields'];
    $mission = \App\Support\Localization\ArabicFormDate::format($fields['mission_date']);
@endphp
<h1>إذن مأمورية</h1>
<p>اسم الموظف / {{ $snapshot['name_ar'] }}<br>
    رقم الموظف / <bdi>{{ $snapshot['code'] }}</bdi> · القسم / {{ $snapshot['department'] ?? '—' }}</p>
<p>الوظيفة / {{ $fields['job_title'] }}</p>
<p>اليوم {{ $mission['weekday'] }} الموافق <bdi>{{ $mission['date'] }}</bdi></p>
<p>سبب المأمورية / <span class="reason">{{ $fields['mission_reason'] }}</span></p>
<div class="approvals">
    <p>مدير القسم / {{ $fields['department_manager'] }}</p>
    <p>توقيع الموارد البشرية / <span class="line"></span></p>
    <p class="company">توقيع مدير الشركة<br><span class="line"></span></p>
</div>
@endsection
