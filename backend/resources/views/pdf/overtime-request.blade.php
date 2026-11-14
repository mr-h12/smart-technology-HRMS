@extends('pdf.layout')

@section('form-css')
    @include('pdf.attendance-request-css')
    .overtime-reason { overflow-wrap: anywhere; white-space: pre-wrap; }
    .signature-row { break-inside: avoid; }
    .hand { display: inline-block; width: 30mm; border-bottom: 1px dotted; }
@endsection

@section('document')
<h1>طلب عمل إضافي / <bdi lang="en" dir="ltr">Overtime Request</bdi></h1>
<table class="request-table request-table-details">
    <tr><th>الاسم</th><td>{{ $snapshot['name_ar'] }} @if (!empty($snapshot['name_en'])) / <bdi>{{ $snapshot['name_en'] }}</bdi> @endif</td><th lang="en" dir="ltr">Employee Name</th></tr>
    <tr><th>رقم {{ $feminine ? 'الموظفة' : 'الموظف' }}</th><td><bdi>{{ $snapshot['code'] }}</bdi></td><th lang="en" dir="ltr">Employee No.</th></tr>
    <tr><th>القسم</th><td>{{ $snapshot['department'] ?? '—' }}</td><th lang="en" dir="ltr">Department</th></tr>
    <tr><th>الوظيفة</th><td>{{ $snapshot['job_title'] }}</td><th lang="en" dir="ltr">Job Title</th></tr>
    <tr><th>تاريخ العمل</th><td>{{ $workDate['weekday'] }} الموافق <bdi>{{ $workDate['date'] }}</bdi></td><th lang="en" dir="ltr">Work Date</th></tr>
    <tr><th>وقت الانصراف</th><td><bdi>{{ $checkout }}</bdi></td><th lang="en" dir="ltr">Checkout Time</th></tr>
    <tr><th>مدة العمل الإضافي</th><td>{{ $duration }}</td><th lang="en" dir="ltr">Overtime Duration</th></tr>
</table>
<div class="block-title">سبب العمل الإضافي / <bdi lang="en" dir="ltr">Reason for Overtime</bdi></div>
<p class="overtime-reason">{{ $snapshot['reason'] }}</p>
<div class="block-title">التوقيعات / <bdi lang="en" dir="ltr">Signatures</bdi></div>
<table class="request-table">
    <tr class="signature-row"><th>مدير القسم</th><td><span class="hand"></span> &nbsp; التاريخ <bdi>____ / ____ / ____</bdi></td><th lang="en" dir="ltr">Department Manager</th></tr>
    <tr class="signature-row"><th>الموارد البشرية</th><td><span class="hand"></span> &nbsp; التاريخ <bdi>____ / ____ / ____</bdi></td><th lang="en" dir="ltr">HR</th></tr>
    <tr class="signature-row"><th>مدير الشركة</th><td><span class="hand"></span> &nbsp; التاريخ <bdi>____ / ____ / ____</bdi></td><th lang="en" dir="ltr">Company Manager</th></tr>
</table>
@endsection
