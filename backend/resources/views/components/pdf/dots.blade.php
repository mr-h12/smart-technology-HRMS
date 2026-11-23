@props(['count', 'pitch' => 1.38])
{{-- Pitch 0 prints plain inline periods at the font's own advance. --}}
<span {{ $attributes->class(['dl' => $pitch > 0]) }}@if ($pitch > 0) style="letter-spacing: {{ $pitch }}pt"@endif>{{ str_repeat('.', $count) }}</span>