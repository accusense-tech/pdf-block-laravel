@php
  use PdfBlock\Laravel\StyleHelpers as S;

  $s = $block['styles'] ?? [];
  $align = $block['alignment'] ?? 'center';
  $items = $align === 'left' ? 'flex-start' : ($align === 'right' ? 'flex-end' : 'center');

  // Estilos do bloco no <figure> + layout em coluna (imagem em cima, legenda embaixo).
  $style = S::blockStyles($s) . ";display:flex;flex-direction:column;align-items:{$items};";

  $w = match(true) {
      ($block['width'] ?? 'full') === 'full' => '100%',
      ($block['width'] ?? 'full') === 'auto' => 'auto',
      default => ((int) $block['width']) . 'px',
  };

  $src = S::safeUrl((string) ($block['src'] ?? ''), true);
  $alt = htmlspecialchars((string) ($block['alt'] ?? ''), ENT_QUOTES);
  $caption = htmlspecialchars((string) ($block['caption'] ?? ''), ENT_QUOTES);
@endphp
<figure class="pdfb-figure" style="{{ $style }}">
  @if($src)
    <img src="{{ $src }}" alt="{{ $alt }}" style="width:{{ $w }};max-width:100%;display:block" />
  @endif
  <figcaption class="pdfb-figure-caption">{!! $caption !!}</figcaption>
</figure>
