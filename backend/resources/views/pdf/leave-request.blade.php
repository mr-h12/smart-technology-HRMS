@extends('pdf.layout')

@section('form-css')
    main { margin: 8mm; padding: 5mm 10mm 11mm; min-height: 280mm; border: 4px double; font-size: 13pt; line-height: 1.65; }
    h1 { border: 1.5px solid; border-radius: 8px; margin: 2mm auto 3mm; padding: 1mm 8mm; width: max-content; text-decoration: none; }
    p { margin: 3mm 0; }
    .brand { text-decoration: underline; text-align: left; font-size: 15pt; }
    .center { text-align: center; }
    bdi { white-space: nowrap; }
    .types { display: flex; justify-content: center; flex-wrap: wrap; gap: 5mm; }
    .type { display: inline-flex; align-items: center; gap: 2mm; }
    .circle { display: inline-block; border: 1.5px solid; width: 5mm; height: 5mm; border-radius: 50%; }
    .marked { box-shadow: inset 0 0 0 1mm white; background: #141414; }
    .manual { width: 75%; margin-inline-start: auto; }
    .line { display: inline-block; width: 34mm; border-bottom: 1px dotted; }
    .fields { border-collapse: collapse; width: 100%; margin-top: 5mm; }
    .fields th, .fields td { border: 1px solid; text-align: start; padding: 1mm 2mm; vertical-align: top; overflow-wrap: anywhere; }
    .fields th { width: 43%; }
    .signatures { display: grid; grid-template-columns: 1fr 1fr; text-align: center; margin-top: 3mm; break-inside: avoid; }
    .signatures img { display: block; width: 40mm; height: 12mm; object-fit: contain; margin-inline: auto; }
@endsection

@section('document')
<div class="brand" dir="ltr">Smart Technology</div>
<h1>نموذج طلب إجازة</h1>
<p><u>مدير الشركة</u></p>
<p class="center">تحية طيبة وبعد،،،</p>
<p>{{ $feminine ? 'مقدمة لسيادتكم' : 'مقدم لسيادتكم' }} / {{ $snapshot['name_ar'] }}</p>
<p class="center"><u>بيانات الطلب</u></p>
<div class="types">
    <span>نوع الإجازة</span>
    @foreach (['annual' => 'اعتيادية', 'casual' => 'عارضة', 'sick' => 'مرضي'] as $kind => $label)
        <span class="type">{{ $label }} <span class="circle{{ $snapshot['kind'] === $kind ? ' marked' : '' }}"></span></span>
    @endforeach
    @if ($snapshot['kind'] === 'unpaid')
        <span class="type">بدون أجر <span class="circle marked"></span></span>
    @endif
</div>
<p>أرجو من سيادتكم الموافقة على منحي إجازة لمدة {{ $duration }} ابتداءً من يوم {{ $from['weekday'] }} الموافق <bdi>{{ $from['date'] }}</bdi>
    إلى يوم {{ $to['weekday'] }} الموافق <bdi>{{ $to['date'] }}</bdi> وذلك لظروف {{ $snapshot['reason'] ?: '........................' }}.</p>
<p class="center">وتفضلوا بقبول فائق الاحترام،،،</p>
<div class="manual">
    <p>{{ $feminine ? 'توقيع مقدمة الطلب' : 'توقيع مقدم الطلب' }} / <span class="line"></span></p>
    <p>الوظيفة / {{ $snapshot['job_title'] }}</p>
    <p>القسم / {{ $snapshot['department'] ?: '........................' }}</p>
    <p>طريقة التبليغ / {{ $snapshot['notification_method'] ?: '........................' }}</p>
    <p>مدير القسم / <span class="line"></span></p>
</div>
<table class="fields">
    <tr><th>الاستعلام عن رصيد الإجازات</th><td>{{ $balanceDays }} — رصيد سنة <bdi>{{ $snapshot['balance_year'] }}</bdi></td></tr>
    <tr><th>رأي مدير القسم</th><td>{{ $snapshot['manager_opinion'] }}</td></tr>
    <tr><th>اعتماد مدير القسم</th><td><span class="line"></span></td></tr>
    <tr><th>توقيع الموظف الذي ينوب في العمل</th><td>{{ $snapshot['substitute'] }} <span class="line"></span></td></tr>
</table>
<div class="signatures">
    @foreach (['company_manager' => 'توقيع مدير الشركة', 'hr' => 'توقيع الموارد البشرية'] as $line => $label)
        <div data-stamp-line="{{ $line }}">
            <div>{{ $label }}</div>
            @if ($signature && ($snapshot['stamp_line'] ?? null) === $line)
                <img src="{{ $signature }}" alt="{{ $label }}">
            @else
                <span class="line"></span>
            @endif
        </div>
    @endforeach
</div>
@endsection
