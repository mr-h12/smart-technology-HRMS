@extends('hr-forms.layout')

@section('form-css')
    body::before { content: ''; position: fixed; inset: 8mm; border: 4px double #141414; }
    main { padding: 18mm 22mm; font-size: 14pt; line-height: 1.5; overflow-wrap: anywhere; }
    h1 { margin-bottom: 5mm; }
    h2 { font-size: 16pt; }
    p { margin: 0 0 3mm; }
    .signature-block { margin-top: 8mm; break-inside: avoid; }
    .line { display: inline-block; width: 45mm; border-bottom: 1px dotted; }
    .address { white-space: pre-wrap; }
@endsection

@section('document')
@php
    $fields = $snapshot['fields'];
    $issued = \App\Support\Localization\ArabicFormDate::format($fields['issued_on']);
    $deadline = \App\Support\Localization\ArabicFormDate::format($fields['reply_deadline']);
    $start = \App\Support\Localization\ArabicFormDate::format($fields['start_date']);
@endphp
<p dir="ltr" lang="en">Smart Technology</p>
<h1>خطاب قبول توظيف</h1>
<p>التاريخ / {{ $issued['weekday'] }} الموافق <bdi>{{ $issued['date'] }}</bdi></p>
<p>إلى / {{ $fields['candidate_name'] }}</p>
<p>العنوان / <span class="address">{{ $fields['address'] }}</span></p>
<p>يسرّنا أن نبلغكم بقبولكم للانضمام إلى فريق عمل سمارت تكنولوجي، لوظيفة {{ $fields['job_title'] }}، وذلك بعد مراجعة سيرتكم الذاتية واجتيازكم بنجاح مراحل التقييم والمقابلة الشخصية.</p>
<p>نودّ إفادتكم بأن الراتب الشهري الذي تم قبولكم عليه هو <bdi>{{ $fields['monthly_salary'] }}</bdi> جنيه مصري، وفترة التقييم {{ \App\Support\Localization\ArabicServiceDuration::months($fields['evaluation_months']) }}، بالإضافة إلى المزايا التي سيتم توضيحها تفصيلًا عند استكمال إجراءات التعيين.</p>
<p>نرجو منكم التكرم بتأكيد قبولكم لهذا العرض. يرجى التوقيع أدناه وإعادة نسخة من هذا العرض في مقر الشركة في موعد أقصاه {{ $deadline['weekday'] }} الموافق <bdi>{{ $deadline['date'] }}</bdi>، والحضور شخصيًا لتسليم مسوغات التعيين، وهي صورة البطاقة الشخصية، وكعب عمل موجّه للشركة، وفيش جنائي موجّه لشركة سمارت تكنولوجي للأنظمة المتكاملة، وأصل المؤهل، وأصل شهادة الميلاد، واستمارة التأمينات أو برنت تأمينات حديث، وصور شخصية، وأصل الموقف من التجنيد.</p>
<p>على أن يكون تاريخ بداية العمل {{ $start['weekday'] }} الموافق <bdi>{{ $start['date'] }}</bdi>.</p>
<p>نتطلع لانضمامكم إلى فريق العمل، ونتمنى لكم التوفيق والنجاح في مسيرتكم المهنية معنا.</p>
<h2>الشروط العامة</h2>
<ul>
    <li>الالتزام بسياسات الشركة وأنظمتها الداخلية.</li>
    <li>تقديم الوثائق المطلوبة قبل مباشرة العمل.</li>
</ul>
<div class="signature-block">
    <p>توقيع الشركة / <span class="line"></span></p>
    <h2>إقرار قبول العرض الوظيفي</h2>
    <p>أقر أنا، الأستاذ(ة) / <span class="line"></span>، بمراجعة بنود هذا العرض والموافقة عليه بالكامل.</p>
    <p>الاسم / <span class="line"></span></p>
    <p>التوقيع / <span class="line"></span> · التاريخ / <bdi>____ / ____ / ____</bdi></p>
    <p>قسم الموارد البشرية</p>
</div>
@endsection
