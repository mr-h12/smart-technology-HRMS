@extends('hr-forms.layout')

@section('form-css')
    main { padding: 24mm; }
    .slip { border: 1px solid; padding: 8mm; }
    .line { display: inline-block; width: 55mm; border-bottom: 1px dotted; }
@endsection

@section('document')
@php($issued = \App\Support\Localization\ArabicFormDate::format($snapshot['fields']['issued_on']))
<div class="slip">
    <h1>استلام خط موبايل</h1>
    <p>الاسم / {{ $snapshot['name_ar'] }}</p>
    <p>رقم الخط / <bdi>{{ $snapshot['fields']['sim_number'] }}</bdi></p>
    <p>التوقيع / <span class="line"></span></p>
    <p>تحريرًا في يوم {{ $issued['weekday'] }} الموافق <bdi>{{ $issued['date'] }}</bdi></p>
</div>
@endsection
