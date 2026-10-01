@php
  use PdfBlock\Laravel\StyleHelpers as S;

  $style = S::blockStyles($block['styles'] ?? []);
  $justify = S::justifyCSS($block['alignment'] ?? 'center');
  $size = $block['size'] ?? 128;
  $fg = $block['fgColor'] ?? '#000000';
  $bg = $block['bgColor'] ?? '#ffffff';
  $data = $block['data'] ?? '';
@endphp
<div style="{{ $style }};display:flex;justify-content:{{ $justify }}">
  <div style="
    width:{{ $size }}px;
    height:{{ $size }}px;
    background-color:{{ $bg }};
    display:flex;
    align-items:center;
    justify-content:center;
    border:1px solid {{ $fg }}20;
    border-radius:4px;
    font-size:11px;
    color:{{ $fg }};
    text-align:center;
    padding:8px;
    word-break:break-all;
  ">QR: {{ Str::limit($data, 40) }}</div>
</div>
