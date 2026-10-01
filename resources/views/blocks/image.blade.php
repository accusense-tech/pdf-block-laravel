@php
  use PdfBlock\Laravel\StyleHelpers as S;

  $s = $block['styles'] ?? [];
  $justify = S::justifyCSS($block['alignment'] ?? 'center');

  // Com cor de fundo, o bloco vira um "card": a sombra precisa contornar o card
  // (este wrapper, que pinta o fundo), não ficar presa em volta da <img> interna.
  // Espelha ImageBlockRenderer (client) — sem fundo, a sombra fica na imagem.
  $hasBg = S::hasColoredBackground($s);
  $shadowCss = S::shadowToCSS($s['shadow'] ?? ['enabled'=>false]);
  $radiusCss = S::cornersToCSS($s['borderRadius'] ?? ['topLeft'=>0,'topRight'=>0,'bottomRight'=>0,'bottomLeft'=>0]);

  $outerStyle = implode('', [
      'padding:' . S::edgeToCSS($s['padding'] ?? ['top'=>0,'right'=>0,'bottom'=>0,'left'=>0]) . ';',
      'margin:' . S::edgeToCSS($s['margin'] ?? ['top'=>0,'right'=>0,'bottom'=>0,'left'=>0]) . ';',
      'opacity:' . ($s['opacity'] ?? 1) . ';',
      S::backgroundToCSS($s['background'] ?? ['type'=>'solid','color'=>'transparent']),
      "display:flex;justify-content:{$justify};",
      $hasBg ? "box-shadow:{$shadowCss};border-radius:{$radiusCss};" : '',
      // Com ZOOM a imagem cresce além da caixa — recorta aqui (espelha o canvas).
      \PdfBlock\Laravel\ImageAdjust::zoom($block['adjust'] ?? null) > 0 ? 'overflow:hidden;' : '',
  ]);

  $w = match(true) {
      ($block['width'] ?? 'auto') === 'auto' => 'auto',
      ($block['width'] ?? 'auto') === 'full' => '100%',
      default => $block['width'] . 'px',
  };
  $h = ($block['height'] ?? 'auto') === 'auto' ? 'auto' : $block['height'] . 'px';

  // Teto de altura para imagens com height:auto. NÃO usar `vh` aqui: no print o
  // viewport é a PRÓPRIA folha, então no modo contínuo (folha = documento inteiro)
  // o teto some no PDF mas continua valendo na medição (viewport do driver, 800px)
  // — a imagem era medida truncada, crescia no print e empurrava uma 2ª página.
  // A var vem do document.blade: altura da folha no paginado, `none` no contínuo.
  $maxH = $h === 'auto' ? 'max-height:var(--pdfb-img-max-h,none);' : '';
  
  // Sanitiza a URL (permite data:image para imagens embutidas em base64).
  $src = S::safeUrl((string) ($block['src'] ?? ''), true);

  $border = $s['border'] ?? [];
  $imgStyle = implode('', [
      "width:{$w};height:{$h};",
      $maxH,
      "object-fit:" . ($block['objectFit'] ?? 'contain') . ";",
      "max-width:100%;display:block;",
      'border-top:' . S::borderSideToCSS($border['top'] ?? ['width'=>0,'style'=>'none','color'=>'#000']) . ';',
      'border-right:' . S::borderSideToCSS($border['right'] ?? ['width'=>0,'style'=>'none','color'=>'#000']) . ';',
      'border-bottom:' . S::borderSideToCSS($border['bottom'] ?? ['width'=>0,'style'=>'none','color'=>'#000']) . ';',
      'border-left:' . S::borderSideToCSS($border['left'] ?? ['width'=>0,'style'=>'none','color'=>'#000']) . ';',
      'border-radius:' . $radiusCss . ';',
      'box-shadow:' . ($hasBg ? 'none' : $shadowCss) . ';',
      // Recorte/cor/zoom da imagem (espelha core/imageAdjust.ts). O clip-path
      // corta só a <img>, nunca o que estiver em volta.
      \PdfBlock\Laravel\ImageAdjust::css($block['adjust'] ?? null),
      ($focus = \PdfBlock\Laravel\ImageAdjust::focus($block['adjust'] ?? null)) !== ''
          ? "object-position:{$focus};" : '',
  ]);
@endphp
<div style="{{ $outerStyle }}">
  @if($src && $src !== '#')
    <img src="{{ $src }}" alt="{{ $block['alt'] ?? '' }}" title="{{ $block['title'] ?? '' }}" style="{{ $imgStyle }}" />
  @endif
</div>
