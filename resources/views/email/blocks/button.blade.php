@php
  use PdfBlock\Laravel\StyleHelpers as S;
  use PdfBlock\Laravel\Email\EmailStyleHelpers as ES;
  use PdfBlock\Laravel\Email\VmlHelpers as V;

  // Botão unificado: o visual vem de `styles`. Migra docs antigos primeiro.
  // E-mail não suporta gradiente/sombra → o fundo cai numa cor sólida.
  $block   = S::migrateButtonBlock($block);
  $styles  = $block['styles'] ?? [];
  $align   = ES::alignAttr($block['alignment'] ?? 'center');
  $href    = $block['url'] ?? '#';
  $text    = $block['text'] ?? 'Button';

  $padding = is_array($styles['padding'] ?? null) ? $styles['padding'] : ['top' => 12, 'right' => 24, 'bottom' => 12, 'left' => 24];
  $padV    = (int) ($padding['top'] ?? 12);
  $padH    = (int) ($padding['right'] ?? 24);
  $fontSize = (int) ($block['fontSize'] ?? 16);
  $fontWeight = (string) ($block['fontWeight'] ?? 600);
  $fontColor = (string) ($block['fontColor'] ?? '#ffffff');
  $bgColor = S::backgroundSolidColor(is_array($styles['background'] ?? null) ? $styles['background'] : [], '#3b82f6');
  $radius  = (int) ($styles['borderRadius']['topLeft'] ?? 6);
  $margin  = S::edgeToCSS($styles['margin'] ?? ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]);

  // Approximate button box size for VML sizing.
  $vmlWidth  = max(120, (int) (strlen($text) * $fontSize * 0.6) + $padH * 2);
  $vmlHeight = $fontSize + $padV * 2 + 4;
  $vmlRadius = $vmlHeight > 0 ? min(50, (int) round(($radius / $vmlHeight) * 100)) : 0;
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:{{ $margin }};">
  <tr><td align="{{ $align }}">
    {!! V::buttonRoundrect($href, $text, [
        'width'      => $vmlWidth,
        'height'     => $vmlHeight,
        'radius'     => $vmlRadius,
        'fill'       => $bgColor,
        'stroke'     => $bgColor,
        'fontSize'   => $fontSize,
        'fontWeight' => $fontWeight,
        'color'      => $fontColor,
        'fontFamily' => $fontStack ?? 'Arial, sans-serif',
    ]) !!}
    <!--[if !mso]><!-- -->
    <a href="{{ e($href) }}" target="_blank" style="display:inline-block;background-color:{{ $bgColor }};color:{{ $fontColor }};font-family:{{ $fontStack ?? 'Arial, sans-serif' }};font-size:{{ $fontSize }}px;font-weight:{{ $fontWeight }};text-decoration:none;padding:{{ $padV }}px {{ $padH }}px;border-radius:{{ $radius }}px;mso-hide:all;">{{ e($text) }}</a>
    <!--<![endif]-->
  </td></tr>
</table>
