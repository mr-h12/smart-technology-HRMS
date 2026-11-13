@extends('hr-forms.layout')

@section('form-css')
    body::before { content: ''; position: fixed; inset: 8mm; border: 5px double #141414; }
    main { padding: 18mm 24mm; font-size: 15pt; line-height: 1.65; overflow-wrap: anywhere; }
    h1 { margin-bottom: 5mm; }
    p { margin: 0 0 5mm; }
    .greeting, .courtesy { text-align: center; }
    .applicant { text-align: left; break-inside: avoid; }
    .line { display: inline-block; width: 46mm; border-bottom: 1px dotted; }
@endsection

@section('document')
@php($employee = $feminine ? 'الموظفة' : 'الموظف')
<h1>استقالة</h1>
<p>السيد / المدير العام لشركة سمارت تكنولوجي للأنظمة المتكاملة</p>
<p class="greeting">تحية طيبة وبعد،،،</p>
<p>برجاء التكرم بالموافقة على قبول استقالتي من العمل بالشركة، علمًا بأنني سألتزم بتسليم كافة المهام الموكلة إليّ خلال فترة العمل، وعلمًا بأنني قد استلمت كافة مستحقاتي (مسوغات التعيين ومستحقاتي المالية) من الشركة.</p>
<p class="courtesy">وتفضلوا سيادتكم بقبول فائق الاحترام والتقدير.</p>
<div class="applicant">
    <p>{{ $feminine ? 'مقدمة' : 'مقدم' }} لسيادتكم / {{ $snapshot['name_ar'] }}</p>
    <p>اسم {{ $employee }} / {{ $snapshot['name_ar'] }}</p>
    <p>رقم {{ $employee }} / <bdi>{{ $snapshot['code'] }}</bdi> · القسم / {{ $snapshot['department'] ?? '—' }}</p>
    <p>التوقيع / <span class="line"></span></p>
    <p>الرقم القومي / <span class="line"></span></p>
</div>
@endsection
