@php
  use PdfBlock\Laravel\StyleHelpers as S;

  $style = S::blockStyles($block['styles'] ?? []);
  $justify = S::justifyCSS($block['alignment'] ?? 'center');
@endphp
<div style="{{ $style }};display:flex;justify-content:{{ $justify }}">
  <hr style="
    width:{{ $block['widthPercent'] ?? 100 }}%;
    border:none;
    border-top:{{ $block['thickness'] ?? 1 }}px {{ $block['lineStyle'] ?? 'solid' }} {{ $block['color'] ?? '#e0e0ec' }};
    margin:0;
  " />
</div>
