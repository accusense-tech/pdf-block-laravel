@php
  use PdfBlock\Laravel\StyleHelpers as S;
  
  $style = S::blockStyles($block['styles'] ?? []) . implode('', array_filter([
      !empty($block['fontFamily']) ? "font-family:" . S::normalizeFontFamily($block['fontFamily']) . ";" : '',
      !empty($block['fontSize']) ? "font-size:{$block['fontSize']}px;" : '',
      // Sem peso próprio o texto HERDA (do documento ou do container).
      ! empty($block['fontWeight']) ? "font-weight:{$block['fontWeight']};" : '',
      !empty($block['fontColor']) ? "color:{$block['fontColor']};" : '',
      ($block['lineHeight'] ?? null) ? "line-height:{$block['lineHeight']};" : '',
      ($block['letterSpacing'] ?? null) ? "letter-spacing:{$block['letterSpacing']}px;" : '',
      "text-align:" . ($block['textAlign'] ?? 'left') . ";",
      ($block['textTransform'] ?? 'none') !== 'none' ? "text-transform:{$block['textTransform']};" : '',
  ]));

  $html = $tiptap->toHtml($block['content'] ?? []);

  // Ids determinísticos nos headings (âncora p/ o Sumário e o target-counter do
  // nº de página). Espelha core/toc.headingAnchorId: pdfb-h-<blockId>-<i>.
  $hIdx = 0;
  $bid = $block['id'] ?? '';
  $html = preg_replace_callback('/<h([1-6])(\s|>)/', function ($m) use ($bid, &$hIdx) {
      return '<h' . $m[1] . ' id="pdfb-h-' . $bid . '-' . ($hIdx++) . '"' . $m[2];
  }, $html);
@endphp
<div class="pdfb-tiptap" style="{{ $style }}">{!! $html !!}</div>
