{{--
  pdf-block::blocks.svg

  Bloco de SVG inline — o "escape hatch" vetorial. Renderiza desenho arbitrário
  (diagramas, ícones, conectores, gráficos custom) como VETOR no PDF. O conteúdo
  é SANITIZADO (SvgSanitizer) antes de injetar: remove <script>, handlers on*,
  <foreignObject> e URLs perigosas — que o Chromium executaria durante o render.
  Espelha o SVGBlockRenderer do React (packages/react/src/blocks/renderers.tsx).
--}}
@php
  use PdfBlock\Laravel\StyleHelpers as S;
  use PdfBlock\Laravel\SvgAttrs;
  use PdfBlock\Laravel\SvgSanitizer;

  $style   = S::blockStyles($block['styles'] ?? []);
  $justify = S::justifyCSS($block['alignment'] ?? 'center');
  $width   = $block['width'] ?? 'auto';
  $height  = $block['height'] ?? 'auto';

  $widthCSS  = $width === 'auto' ? 'auto' : ($width === 'full' ? '100%' : $width . 'px');
  $heightCSS = $height === 'auto' ? 'auto' : $height . 'px';

  // Sanitiza e aplica a APARÊNCIA (cores herdadas + preserveAspectRatio) no <svg>
  // raiz — espelha applySvgAppearance/svgTransform do React.
  $svg = SvgAttrs::apply(SvgSanitizer::sanitize((string) ($block['content'] ?? '')), $block);

  $box = 'width:' . $widthCSS . ';height:' . $heightCSS . ';max-width:100%';
  $opacity = $block['opacity'] ?? null;
  if (is_numeric($opacity) && (float) $opacity !== 1.0) {
      $box .= ';opacity:' . ($opacity + 0);
  }
  $transform = SvgAttrs::transform($block);
  if ($transform !== '') {
      $box .= ';transform:' . $transform;
  }
@endphp
<div style="{{ $style }};display:flex;justify-content:{{ $justify }}">
  <div style="{{ $box }}">
    {!! $svg !!}
  </div>
</div>
