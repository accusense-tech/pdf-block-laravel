@php
  use PdfBlock\Laravel\Email\EmailStyleHelpers as ES;
  use PdfBlock\Laravel\Email\VmlHelpers as V;
  $outer = ES::blockStyles($block['styles'] ?? []);
  $src   = $block['src'] ?? '';
  $h     = (int) ($block['height'] ?? 240);
  $w     = (int) ($block['width'] ?? 600);
  $title = $block['title'] ?? '';
  $subtitle = $block['subtitle'] ?? '';
  $color = $block['textColor'] ?? '#ffffff';
  $fallbackBg = ES::solidFallbackColor($block['styles']['background'] ?? ['type' => 'solid', 'color' => '#0d1b3e']);
@endphp
<div style="{{ $outer }}">
  {!! $src ? V::backgroundRectOpen($src, $w, $h, $fallbackBg) : '<div>' !!}
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:{{ $src ? 'transparent' : $fallbackBg }};">
    <tr>
      <td align="center" height="{{ $h }}" style="height:{{ $h }}px;padding:40px 20px;color:{{ $color }};font-family:{{ $fontStack ?? 'Arial, sans-serif' }};">
        @if($title)<div style="font-size:28px;font-weight:700;color:{{ $color }};">{{ $title }}</div>@endif
        @if($subtitle)<div style="font-size:16px;margin-top:8px;color:{{ $color }};">{{ $subtitle }}</div>@endif
      </td>
    </tr>
  </table>
  {!! $src ? V::backgroundRectClose() : '</div>' !!}
</div>
