@props(['split', 'right', 'left'])
<div {{ $attributes->class(['pair']) }} style="grid-template-columns: {{ $split }}pt 1fr"><p style="padding-left: 7pt">{{ $right }}</p><p>{{ $left }}</p></div>