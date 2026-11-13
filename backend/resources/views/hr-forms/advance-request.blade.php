@extends('hr-forms.layout')

@section('form-css')
    @include('hr-forms.attendance-request-css')
    .pair { display: flex; justify-content: space-between; gap: 3mm; padding: 1mm 2mm; }
    .pair [lang=en] { text-align: left; }
    .box { width: 3mm; height: 3mm; border: 1px solid; display: inline-block; vertical-align: middle; text-align: center; line-height: 2.6mm; }
    .hand { display: inline-block; width: 34mm; border-bottom: 1px dotted; }
    .paper-signatures { break-inside: avoid; }
@endsection

@section('document')
@php($employee = $feminine ? 'الموظفة' : 'الموظف')
<h1>طلب سلفة <span lang="en" dir="ltr">/ Advance Request</span></h1>
<table class="request-table request-table-details">
    <tr><th>التاريخ</th><td>{{ $requestDate['weekday'] }} الموافق <bdi>{{ $requestDate['date'] }}</bdi></td><th lang="en" dir="ltr">Date</th></tr>
    <tr><th>الاسم</th><td>{{ $snapshot['name_ar'] }} @if (!empty($snapshot['name_en'])) / <bdi>{{ $snapshot['name_en'] }}</bdi> @endif</td><th lang="en" dir="ltr">Name</th></tr>
    <tr><th>رقم {{ $employee }}</th><td><bdi>{{ $snapshot['code'] }}</bdi></td><th lang="en" dir="ltr">Employee No.</th></tr>
    <tr><th>القسم</th><td>{{ $snapshot['department'] ?? '—' }}</td><th lang="en" dir="ltr">Dept.</th></tr>
    <tr><th>الوظيفة</th><td>{{ $snapshot['job_title'] }}</td><th lang="en" dir="ltr">Job Title</th></tr>
    <tr><th>مبلغ السلفة</th><td><bdi>{{ $amount }}</bdi></td><th lang="en" dir="ltr">Loan Amount</th></tr>
    <tr><th>سبب السلفة</th><td class="fill free">{{ $snapshot['reason'] }}</td><th lang="en" dir="ltr">Reason for Loan</th></tr>
</table>
<div class="block-title">طريقة التسديد / <span lang="en" dir="ltr">Manner of Payment</span></div>
@foreach (['receipts' => ['عند تقديم الفواتير', 'Present Receipts'], 'cash' => ['الدفع نقدًا', 'Will Pay Cash'], 'salary_deduction' => ['يخصم شهريًا من الراتب', 'Monthly Salary Deduction']] as $code => [$ar, $en])
    <div class="pair">
        <span><span class="box">{{ $snapshot['repayment_method'] === $code ? '×' : '' }}</span> {{ $ar }}</span>
        <span lang="en" dir="ltr"><span class="box">{{ $snapshot['repayment_method'] === $code ? '×' : '' }}</span> {{ $en }}</span>
    </div>
@endforeach
<div class="pair"><span>توقيع {{ $employee }} <span class="hand"></span></span><span lang="en" dir="ltr">Employee Signature <span class="hand"></span></span></div>
<div class="pair"><span>التاريخ: ____ / ____ / ____</span><span lang="en" dir="ltr">Date: ____ / ____ / ____</span></div>
<div class="block-title">بواسطة رئيس القسم / <span lang="en" dir="ltr">To Be Completed by Department Head</span></div>
<div class="pair"><span><span class="box"></span> أوصي بقبول السلفة</span><span lang="en" dir="ltr"><span class="box"></span> Recommending Approval</span></div>
<div class="pair"><span>التوقيع <span class="hand"></span></span><span lang="en" dir="ltr">Signature <span class="hand"></span></span></div>
<div class="pair"><span>التاريخ: ____ / ____ / ____</span><span lang="en" dir="ltr">Date: ____ / ____ / ____</span></div>
<div class="block-title">قسم الشؤون المالية والإدارية / <span lang="en" dir="ltr">Finance &amp; Administration</span></div>
<table class="request-table">
    <tr><th>راتب {{ $employee }}</th><td>@if ($salary === null) غير متاح / <span lang="en" dir="ltr">Unavailable</span> @else <bdi>{{ $salary }}</bdi> @endif</td><th lang="en" dir="ltr">Employee Salary</th></tr>
    <tr><th>تاريخ التعيين</th><td>@if ($hireDate) {{ $hireDate['weekday'] }} الموافق <bdi>{{ $hireDate['date'] }}</bdi> @else — @endif</td><th lang="en" dir="ltr">Hiring Date</th></tr>
    <tr><th>هل توجد سلفة سابقة؟</th><td>{{ $snapshot['previous_advance'] ? 'نعم / Yes' : 'لا / No' }}</td><th lang="en" dir="ltr">Any Previous Loan?</th></tr>
    <tr><th>ملاحظات</th><td class="fill free">{{ $snapshot['finance_remarks'] }}</td><th lang="en" dir="ltr">Remarks</th></tr>
</table>
<div class="pair"><span>توقيع المالية <span class="hand"></span></span><span lang="en" dir="ltr">Finance Signature <span class="hand"></span></span></div>
<div class="paper-signatures">
    <div class="block-title">المدير العام / <span lang="en" dir="ltr">General Manager</span></div>
    <div class="pair"><span><span class="box"></span> موافق</span><span lang="en" dir="ltr"><span class="box"></span> Approved</span></div>
    <div class="pair"><span><span class="box"></span> غير موافق</span><span lang="en" dir="ltr"><span class="box"></span> Not Approved</span></div>
    <div class="pair"><span>التوقيع <span class="hand"></span></span><span lang="en" dir="ltr">Signature <span class="hand"></span></span></div>
    <div class="pair"><span>التاريخ: ____ / ____ / ____</span><span lang="en" dir="ltr">Date: ____ / ____ / ____</span></div>
</div>
@endsection
