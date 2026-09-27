@php
  use PdfBlock\Laravel\StyleHelpers as S;
@endphp
<div style="{{ S::blockStyles($block['styles'] ?? []) }};height:{{ $block['height'] ?? 20 }}px"></div>
