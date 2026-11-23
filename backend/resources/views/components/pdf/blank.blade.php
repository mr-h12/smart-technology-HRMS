@props(['at', 'label', 'dots'])
<p class="slot" style="--at: {{ $at }}pt"><span>{{ $label }}<x-pdf.dots :count="$dots" /></span></p>