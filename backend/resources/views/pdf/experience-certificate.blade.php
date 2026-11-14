@extends('pdf.layout')

@section('document')
<h1>شهادة خبرة</h1>
<p>اليوم {{ $issuedOn['weekday'] }} &nbsp; التاريخ <bdi>{{ $issuedOn['date'] }}</bdi></p>
<p>هذه الشهادة مقدمة من شركة سمارت تكنولوجي للأنظمة المتكاملة بأن {{ $feminine ? 'الموظفة المذكورة بياناتها أدناه' : 'الموظف المذكور بياناته أدناه' }}</p>
<table class="details">
<tr><td>اسم الموظف / {{ $snapshot['name_ar'] }}</td><td>المسمى الوظيفي / {{ $snapshot['job_title'] }}</td></tr>
<tr><td>بطاقة رقم قومي / <bdi>{{ $snapshot['national_id'] }}</bdi></td><td>قسم / {{ $snapshot['department'] }}</td></tr>
</table>
<p>{{ $feminine ? 'انضمت' : 'انضم' }} للعمل بوظيفة {{ $snapshot['job_title'] }} ضمن فريق العمل في الشركة لمدة {{ $service }}.
</p>
@if ($praise !== '')
<p class="praise">{{ $praise }}</p>
@endif
<p>تم تحرير هذه الشهادة في يوم {{ $issuedOn['weekday'] }} الموافق <bdi>{{ $issuedOn['date'] }}</bdi> بناءً على رغبة {{ $feminine ? 'الموظفة' : 'الموظف' }} دون أدنى مسؤولية من الشركة.</p>
<div class="signature">
    مدير الشركة
    @if ($signature)
        <img src="{{ $signature }}" alt="توقيع مدير الشركة">
    @else
        <span class="signature-line"></span>
    @endif
</div>
@endsection
