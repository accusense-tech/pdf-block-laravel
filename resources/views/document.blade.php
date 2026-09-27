{{-- 
  pdf-block::document
  
  Main layout template — renders the full DSL document as standalone HTML.
  Mirrors the React StripeRenderer → StructureRenderer → ContentBlock pipeline.
--}}
@php
  $globalStyles = $doc['globalStyles'] ?? [];
  $pageSettings = $doc['pageSettings'] ?? [];
  // Valores CRUS da DSL são interpolados em <style>/@page abaixo → sanitizados
  // aqui (CssSanitizer) para impedir injeção de CSS (fechar a regra e injetar
  // outras). Tokens {{token:...}} já foram resolvidos pelo ThemeResolver antes.
  $pageBg    = \PdfBlock\Laravel\CssSanitizer::color($globalStyles['pageBackground'] ?? '#ffffff', '#ffffff');
  $contentBgRaw = $globalStyles['contentBackground'] ?? '';
  $contentBg = $contentBgRaw === '' ? '' : \PdfBlock\Laravel\CssSanitizer::color($contentBgRaw, ''); // '' = transparente (herda a página)
  $defaultColor = \PdfBlock\Laravel\CssSanitizer::color($globalStyles['defaultFontColor'] ?? '#333333', '#333333');
  $defaultFontRaw = $pageSettings['defaultFontFamily'] ?? 'Spectral, serif';
  $defaultFont = \PdfBlock\Laravel\CssSanitizer::fontFamily(\PdfBlock\Laravel\StyleHelpers::normalizeFontFamily($defaultFontRaw), 'Spectral, serif');
  $defaultFontSize = \PdfBlock\Laravel\CssSanitizer::number($globalStyles['defaultFontSize'] ?? 16, '16');
  $blockquoteBorderColor = \PdfBlock\Laravel\CssSanitizer::color($globalStyles['blockquoteBorderColor'] ?? '#e0e0e0', '#e0e0e0');

  // Page margins in px — same formula as React's mmToPx (round(mm * 96/25.4, 2)).
  // We apply these as body padding so the full paper-width viewport is used and
  // pageBg fills the entire page (including the margin strips in the PDF).
  // The content area inside the body padding equals the React content area width.
  $m = $pageSettings['margins'] ?? ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20];
  // Margens numéricas (mm) — sanitizadas: entram cruas em @page/body/JS abaixo.
  $m = [
    'top'    => \PdfBlock\Laravel\CssSanitizer::number($m['top'] ?? 20, '20'),
    'right'  => \PdfBlock\Laravel\CssSanitizer::number($m['right'] ?? 20, '20'),
    'bottom' => \PdfBlock\Laravel\CssSanitizer::number($m['bottom'] ?? 20, '20'),
    'left'   => \PdfBlock\Laravel\CssSanitizer::number($m['left'] ?? 20, '20'),
  ];
  $mmToPx = fn(float $mm): float => round($mm * 96 / 25.4, 2);
  $padTop    = $mmToPx($m['top']);
  $padRight  = $mmToPx($m['right']);
  $padBottom = $mmToPx($m['bottom']);
  $padLeft   = $mmToPx($m['left']);

  // Paper size for @page — respects orientation.
  $paper     = $pageSettings['paperSize'] ?? ['width' => 210, 'height' => 297];
  $landscape = ($pageSettings['orientation'] ?? 'portrait') === 'landscape';
  $pageW     = \PdfBlock\Laravel\CssSanitizer::number($landscape ? ($paper['height'] ?? 297) : ($paper['width'] ?? 210), '210');
  $pageH     = \PdfBlock\Laravel\CssSanitizer::number($landscape ? ($paper['width'] ?? 210) : ($paper['height'] ?? 297), '297');

  // Modo de página: 'continuous' (single pager, padrão) ou 'paginated'.
  $paginated = ($pageSettings['pageMode'] ?? 'continuous') === 'paginated';

  // Cabeçalho/rodapé repetidos → @page margin boxes (só no modo paginado).
  $docTitle  = $doc['meta']['title'] ?? '';
  $furnDate  = date('d/m/Y');
  $header    = $pageSettings['header'] ?? null;
  $footer    = $pageSettings['footer'] ?? null;
  $isV3render = $isV3 ?? false;
  // Motor 'js' (F5): o bootstrap MATERIALIZA as folhas (self-paginating); @page só
  // declara tamanho+margem-zero, sem @page nomeada/margin-boxes. Só paginado v3.
  // Ver HANDOFF-PAGINATOR.md. Seam: config pdf-block.pagination.engine.
  $engineJs = $paginated && $isV3render && (($paginationEngine ?? 'css') === 'js');
  // v3 (P8/A2): cada Seção vira uma @page NOMEADA própria → header/footer POR SEÇÃO
  // + visibilidade (showOn). v2/e-mail: mantém UM @page global (header/footer da DSL).
  $namedPagesCss = ($paginated && $isV3render && ! $engineJs)
      ? \PdfBlock\Laravel\StyleHelpers::namedPageCss($sectionsV3 ?? [], $pageLayouts ?? [], $docTitle, $furnDate, $defaultColor)
      : '';
  $marginBoxes = ($paginated && ! $isV3render)
      ? \PdfBlock\Laravel\StyleHelpers::pageMarginBoxes($header, $footer, $docTitle, $furnDate, $defaultColor)
      : '';
  // Zera a mobília na 1ª página quando showOnFirstPage === false (só v2; no v3 o
  // controle de 1ª página vem do showOn via @page nome:first em namedPageCss).
  $firstPageReset = '';
  if ($paginated && ! $isV3render) {
      if (is_array($header) && ($header['showOnFirstPage'] ?? true) === false) {
          $firstPageReset .= '@top-left{content:none}@top-center{content:none}@top-right{content:none}';
      }
      if (is_array($footer) && ($footer['showOnFirstPage'] ?? true) === false) {
          $firstPageReset .= '@bottom-left{content:none}@bottom-center{content:none}@bottom-right{content:none}';
      }
  }

  // Fontes resolvidas via fontconfig do sistema (instaladas em
  // /usr/local/share/fonts/pdfblock pelo Dockerfile.browserless).
  // Nenhum @font-face é emitido — zero tráfego de rede para fontes.
@endphp
<!DOCTYPE html>
<html lang="{{ $doc['meta']['locale'] ?? 'pt-BR' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $doc['meta']['title'] ?? 'Document' }}</title>
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    /* O background do elemento raiz é propagado para o canvas inteiro da página
       no print (de borda a borda, cobrindo também as tiras de margem do body),
       MESMO quando o body tem overflow:hidden — que no Chromium SUPRIME a
       propagação body→canvas e deixava as bordas brancas no PDF exportado.
       Pintar o pageBg no <html> garante que a cor de fundo chegue a todas as
       bordas (o body mantém o seu bg como reforço). */
    html {
      background: {{ $pageBg }};
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
      /* Teto de altura das imagens com height:auto (blocks/image.blade). No
         paginado equivale ao antigo `100vh` do print (viewport = folha); no
         contínuo a folha É o documento inteiro, então limitar não faz sentido —
         e o `vh` ainda envenenava a MEDIÇÃO, que roda no viewport do driver. */
      --pdfb-img-max-h: {{ $paginated ? "{$pageH}mm" : 'none' }};
    }
    body {
      /* Modo contínuo: pageBg + padding = margens preenchem o viewport inteiro
         (as margens viram tiras coloridas no PDF).
         Modo paginado: as margens vão para o @page (repetem por página), então
         o body fica sem padding e ocupa a área de conteúdo de cada folha. */
      background: {{ $pageBg }};
      padding: {{ $paginated ? '0' : "{$padTop}px {$padRight}px {$padBottom}px {$padLeft}px" }};
      font-family: {!! $defaultFont !!};
      font-size: {{ $defaultFontSize }}px;
      color: {{ $defaultColor }};
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
      /* Prevent Chromium from synthesising bold/italic/weight variants that
         are not installed — avoids fonts appearing thinner than in the browser. */
      font-synthesis: none;
@if(!$paginated)
      /* Prevent any element from creating a second page in single-page PDF mode.
         overflow:hidden clips anything that extends beyond the measured scrollHeight. */
      overflow: hidden;
      /* LARGURA FIXA = papel: a MEDIÇÃO da altura (JS abaixo) fica idêntica ao
         layout do print INDEPENDENTE do viewport do browser. Sem isto, um
         viewport diferente da largura do papel (ex.: driver medindo em A4 um
         papel custom) reflui o texto, mede uma altura errada e o PDF sai com
         uma cauda gigante de fundo (ou conteúdo cortado). */
      width: {{ $pageW }}mm;
@endif
    }
    img { max-width: 100%; display: block; }
@if($paginated && $engineJs)
    /* ── F5 motor 'js': self-paginating. O bootstrap materializa .pdf-page a
       partir do conteúdo medido; o @page só declara tamanho físico + margem zero
       e cada .pdf-page é UMA folha. Sem margin-boxes/@page nomeada. */
    @page { size: {{ $pageW }}mm {{ $pageH }}mm; margin: 0; background: {{ $pageBg }}; }
    .pdf-page { width: {{ $pageW }}mm; height: {{ $pageH }}mm; position: relative; overflow: hidden; background: {{ $pageBg }}; break-after: page; page-break-after: always; }
    .pdf-page:last-child { break-after: auto; page-break-after: auto; }
    /* Some com o fluxo contínuo original assim que as folhas são materializadas. */
    html[data-pdfb-paginated] > body > .pdfb-content-area { display: none !important; }
@elseif($paginated)
    /* ── Modo paginado: páginas físicas com quebras reais do Chrome ──────
       O @page define o tamanho fixo da folha + margens (que repetem em toda
       página) + margin boxes de cabeçalho/rodapé. O Chrome pagina sozinho. */
    @page {
      size: {{ $pageW }}mm {{ $pageH }}mm;
      margin: {{ $m['top'] }}mm {{ $m['right'] }}mm {{ $m['bottom'] }}mm {{ $m['left'] }}mm;
      /* pinta a folha inteira (inclui as tiras de margem onde ficam header/footer);
         sem isso as margens do @page saem brancas no PDF, mesmo com bg em html/body. */
      background: {{ $pageBg }};
{!! $marginBoxes !!}
    }
@if($firstPageReset !== '')
    @page :first { {!! $firstPageReset !!} }
@endif
@if($namedPagesCss !== '')
    /* v3: @page NOMEADA por seção (header/footer por seção + showOn) */
{!! $namedPagesCss !!}
@endif
    /* Quebra manual: faixas marcadas como "iniciar em nova página" / "quebrar após". */
    [data-break-before="true"] { break-before: page; page-break-before: always; }
    [data-break-after="true"]  { break-after: page;  page-break-after: always; }
    .pdfb-block-pagebreak      { break-after: page;  page-break-after: always; }
    /* Manter junto: faixas marcadas + structures multi-coluna/banner (indivisíveis,
       igual ao preview) + tabelas/imagens/botões por padrão. */
    [data-keep-together="true"], [data-multi-column="true"], [data-atomic="true"],
    table, .pdfb-block-image, .pdfb-block-button-wrapper {
      break-inside: avoid; page-break-inside: avoid;
    }
    /* Evitar linhas órfãs/viúvas no texto. orphans/widows são HERDADOS → o default
       fica no container e um bloco pode sobrescrever via inline (meta.orphans/widows). */
    .pdfb-content-area { orphans: 2; widows: 2; }
@else
    /* ── Single-page mode: suppress ALL page breaks ─────────────────────
       Break properties are suppressed so Chrome never splits the content.
       The actual page height is set dynamically via JS (beforeprint). */
    * {
      break-inside: avoid !important;
      break-before: avoid !important;
      break-after: avoid !important;
      page-break-inside: avoid !important;
      page-break-before: avoid !important;
      page-break-after: avoid !important;
    }
    /* Force zero Chrome PDF margins — all spacing is in body padding. */
    @page { margin: 0; background: {{ $pageBg }}; }
    /* ── Faixa branca no rodapé (single-page) ────────────────────────────
       A altura da página é MEDIDA do body, que é um BFC (overflow:hidden). Logo
       a margin-bottom do ÚLTIMO elemento do fluxo NÃO colapsa com o body: fica
       retida entre o conteúdo e a margem inferior (padding do body), virando uma
       faixa branca no rodapé do PDF. Zeramos a margem SÓ ao longo do "último
       caminho" (cada `> :last-child` encadeado a partir da área de conteúdo) —
       remove a margem vazada em qualquer profundidade (stripe → estrutura →
       coluna → bloco → parágrafo) sem tocar em espaçamentos internos. */
    .pdfb-content-area > :last-child,
    .pdfb-content-area > :last-child > :last-child,
    .pdfb-content-area > :last-child > :last-child > :last-child,
    .pdfb-content-area > :last-child > :last-child > :last-child > :last-child,
    .pdfb-content-area > :last-child > :last-child > :last-child > :last-child > :last-child,
    .pdfb-content-area > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child,
    .pdfb-content-area > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child,
    .pdfb-content-area > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child,
    .pdfb-content-area > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child > :last-child {
      margin-bottom: 0 !important;
    }
@endif
    a { color: inherit; text-decoration: none; }
    /* Force table elements to inherit body font — Chromium's UA stylesheet
       does NOT propagate font-family/size into table/td/th by default. */
    table, thead, tbody, tr, th, td {
      font-family: inherit;
      font-size: inherit;
      color: inherit;
    }
    table { border-collapse: collapse; }
    .pdfb-content-area {
      /* contentBg on the content area (100% of body's content box = paper
         width minus left+right padding/margins). The pageBg is visible in
         the surrounding body padding zone (the margin strips). Vazio =
         transparente → a área de conteúdo herda o fundo da página. */
@if($contentBg)      background: {{ $contentBg }};
@endif      width: 100%;
    }

    /* ── TipTap / ProseMirror rendered content ──────────────────────────
       tiptap-php outputs raw HTML (p, h1–h6, ul, ol, blockquote, etc.)
       without any surrounding .ProseMirror wrapper, so we scope to the
       .pdfb-tiptap class added by text.blade.php.
    ── */
    /* ── Chromium PDF whitespace fix ─────────────────────────────────────
       TipTap's .ProseMirror uses white-space:break-spaces (which overrides
       pre-wrap), word-wrap:break-word, and disables ligatures. We mirror
       all four properties here so the PDF rendering matches the editor.

       break-spaces: like pre-wrap but also preserves sequences of spaces
       between words, matching exactly what TipTap renders in the browser.
       word-wrap: break-word prevents overflow on very long words (URLs).
       font-feature-settings / font-variant-ligatures: TipTap disables
       ligatures to avoid accessibility issues with screen readers — we
       mirror this so character-pair visual width is identical.
    ── */
    .pdfb-tiptap {
      white-space: break-spaces;
      word-wrap: break-word;
      -webkit-font-variant-ligatures: none;
      font-variant-ligatures: none;
      font-feature-settings: "liga" 0;
    }
    .pdfb-tiptap p   { margin: 0; }
    /* Empty paragraphs (from TipTap empty lines) must have line height.
       tiptap-php emits <p><br></p> after our post-processing, but keep
       this rule as a safety net for any that slip through.
       Trailing empty paragraphs (last-child) are suppressed — they are
       auto-inserted by TipTap after headings and should not render. */
    .pdfb-tiptap p:empty:not(:last-child) { display: block; min-height: 1em; }
    .pdfb-tiptap p:not(:last-child) > br:only-child { display: block; min-height: 1em; }
    .pdfb-tiptap p:empty:last-child,
    .pdfb-tiptap p:last-child:has(> br:only-child) { display: none; }
    .pdfb-tiptap h1  { font-size: 2em;    font-weight: 700; margin: 0 0 0.2em;  line-height: 1.2; white-space: pre-wrap; }
    .pdfb-tiptap h2  { font-size: 1.5em;  font-weight: 700; margin: 0 0 0.2em;  line-height: 1.3; white-space: pre-wrap; }
    .pdfb-tiptap h3  { font-size: 1.17em; font-weight: 600; margin: 0 0 0.2em;  line-height: 1.3; white-space: pre-wrap; }
    .pdfb-tiptap h4  { font-size: 1em;    font-weight: 600; margin: 0 0 0.15em; white-space: pre-wrap; }
    .pdfb-tiptap h5  { font-size: 0.83em; font-weight: 600; margin: 0 0 0.15em; white-space: pre-wrap; }
    .pdfb-tiptap h6  { font-size: 0.75em; font-weight: 600; margin: 0 0 0.15em; white-space: pre-wrap; }
    /* Marcador de lista DECLARADO (não herdado do UA): o preflight do Tailwind —
       que os apps consumidores carregam — zera `ol, ul, menu { list-style: none }`,
       e sem um `list-style-type` nosso a lista aparecia SEM bolinha/número no
       editor e no print do navegador (que reusa o CSS da página). Aqui o HTML é
       avulso e o UA bastaria; fica declarado para não divergir do editor. */
    .pdfb-tiptap ul,
    .pdfb-tiptap ol  { padding-left: 1.5em; margin: 0.4em 0; list-style-position: outside; }
    .pdfb-tiptap ul  { list-style-type: disc; }
    .pdfb-tiptap ol  { list-style-type: decimal; }
    .pdfb-tiptap ul ul { list-style-type: circle; }
    .pdfb-tiptap ul ul ul { list-style-type: square; }
    .pdfb-tiptap li  { display: list-item; margin: 0.15em 0; white-space: pre-wrap; }
    .pdfb-tiptap blockquote {
      border-left: 3px solid {{ $blockquoteBorderColor }};
      padding-left: 1em;
      margin: 0.4em 0;
      color: #6b6b80;
      font-style: italic;
    }
    .pdfb-tiptap a   { color: #5b8cff; text-decoration: underline; }
    /* Link (StyledLink): herda a cor do texto por padrão (o style inline do <a>
       sobrepõe quando há cor específica) — casa com o editor. Mais específico que
       `.pdfb-tiptap a` para não pegar o azul; o xref (.pdfb-xref) segue distinto. */
    .pdfb-tiptap a.pdfb-link { color: inherit; }
    .pdfb-tiptap strong { font-weight: 700; }
    .pdfb-tiptap em     { font-style: italic; }
    .pdfb-tiptap u      { text-decoration: underline; }
    .pdfb-tiptap s      { text-decoration: line-through; }
    .pdfb-tiptap code   {
      font-family: monospace;
      background: rgba(0,0,0,0.06);
      padding: 0.1em 0.3em;
      border-radius: 3px;
    }
    .pdfb-tiptap pre  {
      background: #1e1e2e;
      color: #cdd6f4;
      padding: 1em;
      border-radius: 6px;
      overflow: auto;
      font-family: monospace;
      font-size: 0.9em;
    }
    /* Divider / button / image layout helpers */
    .pdfb-block-divider   { display: flex; padding: 4px 0; }
    .pdfb-block-image     { display: flex; }
    .pdfb-block-button-wrapper { display: flex; }
    .pdfb-block-button-wrapper a { text-decoration: none; }
    /* Figure: numeração automática da legenda (counter) — espelha o editor.css. */
    body { counter-reset: pdfb-figure; }
    .pdfb-figure { counter-increment: pdfb-figure; }
    .pdfb-figure-caption { font-size: 13px; line-height: 1.5; margin-top: 6px; color: #667085; }
    .pdfb-figure-caption::before { content: "Figura " counter(pdfb-figure) ". "; font-weight: 600; color: #1a1a2e; }
    .pdfb-figure-caption:empty::before { content: "Figura " counter(pdfb-figure); }
    /* TOC (sumário) — espelha o editor.css; target-counter imprime o nº de página. */
    .pdfb-toc-title { font-size: 18px; font-weight: 700; margin: 0 0 10px; }
    .pdfb-toc-list { list-style: none; margin: 0; padding: 0; }
    .pdfb-toc-entry { line-height: 1.9; }
    .pdfb-toc-entry[data-level="2"] { margin-left: 18px; }
    .pdfb-toc-entry[data-level="3"] { margin-left: 36px; }
    .pdfb-toc-entry[data-level="4"] { margin-left: 54px; }
    .pdfb-toc-entry[data-level="5"] { margin-left: 72px; }
    .pdfb-toc-entry[data-level="6"] { margin-left: 90px; }
    .pdfb-toc-entry a { display: flex; align-items: baseline; text-decoration: none; color: inherit; }
    .pdfb-toc-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pdfb-toc-empty { color: #98a2b3; font-style: italic; }
    .pdfb-toc[data-page-numbers="1"] .pdfb-toc-text { flex: 1; }
    .pdfb-toc[data-page-numbers="1"] .pdfb-toc-entry a::after {
      content: target-counter(attr(href url), page); margin-left: 8px; font-variant-numeric: tabular-nums;
    }
    /* Cross-reference (referência cruzada): link ao alvo; nº de página via target-counter. */
    .pdfb-xref { color: #5b8cff; text-decoration: none; }
    .pdfb-xref[data-mode="page"]::after { content: " " target-counter(attr(href url), page); font-variant-numeric: tabular-nums; }
    .pdfb-xref[data-mode="label-page"]::after { content: " (p. " target-counter(attr(href url), page) ")"; font-variant-numeric: tabular-nums; }
  </style>
</head>
<body>
<div class="pdfb-content-area">
  @if($isV3 ?? false)
    {{-- Render NATIVO v3 (Seções/fluxo) — sem achatar para v2. --}}
    @include('pdf-block::v3.document-body', ['sections' => $sectionsV3 ?? [], 'paginated' => $paginated])
  @else
    @foreach($doc['blocks'] ?? [] as $stripe)
      @if($stripe['meta']['hideOnExport'] ?? false)
        @continue
      @endif
      @include('pdf-block::stripe', ['stripe' => $stripe])
    @endforeach
  @endif
</div>

@if($paginated && $engineJs)
{{-- F5: HTML auto-paginante. Mede o conteúdo e MATERIALIZA folhas físicas
     (.pdf-page) por clip-window; header/footer (COMPONENTES) clonados por página
     com showOn + tokens {page}/{pages}. O Chromium só imprime. Ver HANDOFF-PAGINATOR.md. --}}
@if(is_array($furnitureHeader ?? null) && ($furnitureHeader['enabled'] ?? false) && !empty($furnitureHeader['flow']))
<template id="pdfb-furniture-header" data-showon="{{ $furnitureHeader['showOn'] ?? 'all' }}">@include('pdf-block::v3.stripe-flow', ['flow' => $furnitureHeader['flow'], 'firstBreak' => false])</template>
@endif
@if(is_array($furnitureFooter ?? null) && ($furnitureFooter['enabled'] ?? false) && !empty($furnitureFooter['flow']))
<template id="pdfb-furniture-footer" data-showon="{{ $furnitureFooter['showOn'] ?? 'all' }}">@include('pdf-block::v3.stripe-flow', ['flow' => $furnitureFooter['flow'], 'firstBreak' => false])</template>
@endif
<script>
{!! \PdfBlock\Laravel\PaginatorScript::core() !!}
</script>
<script>
(function () {
  var PW = {{ $pageW }}, PH = {{ $pageH }};
  var M = { top: {{ $m['top'] }}, right: {{ $m['right'] }}, bottom: {{ $m['bottom'] }}, left: {{ $m['left'] }} };
  var mm = function (v) { return v * 96 / 25.4; };
  var mTop = mm(M.top), mRight = mm(M.right), mBottom = mm(M.bottom), mLeft = mm(M.left);
  var innerW = mm(PW) - mLeft - mRight;
  var headerTpl = document.getElementById('pdfb-furniture-header');
  var footerTpl = document.getElementById('pdfb-furniture-footer');

  // Altura DINÂMICA do header/footer: mede o conteúdo real (mesma largura útil) e
  // RESERVA essa altura ABAIXO/ACIMA das margens — espelha o canvas (CanvasArea:
  // usable = pageH - margens - header - footer; content começa em margem+header).
  function measure(tpl) {
    if (!tpl) return 0;
    var probe = document.createElement('div');
    probe.style.cssText = 'position:absolute;left:-99999px;top:0;width:' + innerW + 'px;visibility:hidden;';
    probe.appendChild(tpl.content ? tpl.content.cloneNode(true) : tpl.cloneNode(true));
    document.body.appendChild(probe);
    var h = Math.ceil(probe.getBoundingClientRect().height);
    document.body.removeChild(probe);
    return h;
  }
  var headerH = 0, footerH = 0, usableH = Math.max(1, mm(PH) - mTop - mBottom);

  function ready() {
    return Promise.all([
      document.fonts.ready,
      Promise.all(Array.prototype.slice.call(document.images)
        .filter(function (i) { return !i.complete; })
        .map(function (i) { return new Promise(function (r) { i.onload = i.onerror = r; }); }))
    ]);
  }

  // Header/footer da página i (1 componente lógico → N cópias INERTES). showOn +
  // substituição de tokens {page}/{pages}. Retorna um nó pronto ou null (não visível).
  function furniture(tpl, i, N) {
    if (!tpl) return null;
    var showOn = tpl.getAttribute('data-showon') || 'all';
    var first = i === 0, last = i === N - 1;
    var vis = showOn === 'all'
      || (showOn === 'except-first' && !first)
      || (showOn === 'first-only' && first)
      || (showOn === 'last-only' && last)
      || (showOn === 'first-and-last' && (first || last));
    if (!vis) return null;
    var host = document.createElement('div');
    host.appendChild(tpl.content ? tpl.content.cloneNode(true) : tpl.cloneNode(true));
    host.innerHTML = host.innerHTML.replace(/\{page\}/g, i + 1).replace(/\{pages\}/g, N);
    return host;
  }

  function band(node, pos, h) {
    var box = document.createElement('div');
    box.style.cssText = 'position:absolute;left:' + mLeft + 'px;width:' + innerW + 'px;height:' + h + 'px;overflow:hidden;' + pos;
    box.appendChild(node);
    return box;
  }

  // avoidSpans/forcedCuts/computeCuts vêm do NÚCLEO COMPARTILHADO injetado acima
  // (PdfbPaginatorCore — fonte única em packages/schema/paginator/paginator-core.cjs,
  // testada pelo client; `pnpm gen` sincroniza a cópia de resources/js/).
  var CORE = PdfbPaginatorCore;

  function paginate() {
    var content = document.querySelector('.pdfb-content-area');
    if (!content) return;
    // Mede a altura real do header/footer e reserva do conteúdo (altura dinâmica).
    headerH = measure(headerTpl);
    footerH = measure(footerTpl);
    usableH = Math.max(1, mm(PH) - mTop - mBottom - headerH - footerH);
    var H = Math.max(content.scrollHeight, Math.ceil(content.getBoundingClientRect().height));
    var cuts = CORE.computeCuts(H, CORE.avoidSpans(content), CORE.forcedCuts(content, H), usableH);
    var N = cuts.length - 1;
    var frag = document.createDocumentFragment();
    for (var i = 0; i < N; i++) {
      var winTop = cuts[i];
      var winH = Math.min(usableH, cuts[i + 1] - cuts[i]); // janela REAL desta página (respeita o corte)
      var page = document.createElement('div');
      page.className = 'pdf-page';
      // Conteúdo: viewport (inset pelas margens) clipado à janela, deslocado -cuts[i].
      var vp = document.createElement('div');
      vp.style.cssText = 'position:absolute;left:' + mLeft + 'px;top:' + (mTop + headerH) + 'px;width:' + innerW + 'px;height:' + winH + 'px;overflow:hidden;';
      var clone = content.cloneNode(true);
      clone.style.display = 'block';
      clone.style.marginTop = (-winTop) + 'px';
      clone.style.width = innerW + 'px';
      vp.appendChild(clone);
      page.appendChild(vp);
      // Header/footer nas faixas RESERVADAS (abaixo do topo / acima da base da margem).
      var hn = furniture(headerTpl, i, N); if (hn) page.appendChild(band(hn, 'top:' + mTop + 'px;', headerH));
      var fn = furniture(footerTpl, i, N); if (fn) page.appendChild(band(fn, 'bottom:' + mBottom + 'px;', footerH));
      frag.appendChild(page);
    }
    document.body.appendChild(frag);
    document.documentElement.setAttribute('data-pdfb-paginated', '1');
  }

  function run() { if (!document.documentElement.hasAttribute('data-pdfb-paginated')) paginate(); }
  window.addEventListener('load', function () { ready().then(run); });
  // Fallback SÍNCRONO: garante a materialização antes do Chromium compor o PDF,
  // mesmo se o print disparar antes do ready() assíncrono (idempotente).
  window.addEventListener('beforeprint', run);
}());
</script>
@endif

@if(!$paginated)
{{-- Dynamic single-page size: measure scrollHeight on beforeprint and inject @page size.
     Chromium fires beforeprint synchronously before rendering the PDF, so the
     @page size update takes effect before any page compositing happens.
     (Apenas no modo contínuo — no paginado o @page tem tamanho fixo.) --}}
<style id="pdfb-page-size">@page { margin: 0; background: {{ $pageBg }}; }</style>
<script>
(function () {
    var W = {{ $pageW }};
    var BG = {!! json_encode($pageBg, JSON_UNESCAPED_SLASHES) !!};
    var cachedMm = null;

    function measure() {
        // Altura REAL do conteúdo (só o body, que tem overflow:hidden → reflete o
        // conteúdo). NÃO usar document.documentElement.scrollHeight: o <html>
        // ocupa no mínimo a altura do VIEWPORT (800px no browserless, que gera o
        // PDF via page.pdf() — NÃO dispara beforeprint), então quando o conteúdo
        // é menor que o viewport o scrollHeight do <html> devolve 800 e a @page
        // sai gigante, com uma faixa branca enorme no rodapé (modo contínuo).
        var byRect   = Math.ceil(document.body.getBoundingClientRect().height);
        var byScroll = document.body.scrollHeight;
        // +2px absorbs sub-pixel rounding in Chrome's compositor.
        return (((Math.max(byRect, byScroll) + 2) / 96) * 25.4).toFixed(4);
    }

    function apply(mm) {
        document.getElementById('pdfb-page-size').textContent =
            '@page { size: ' + W + 'mm ' + mm + 'mm; margin: 0; background: ' + BG + '; }';
    }

    // Pre-measure after fonts AND images are fully loaded so scrollHeight is stable.
    window.addEventListener('load', function () {
        Promise.all([
            document.fonts.ready,
            Promise.all(
                Array.from(document.images)
                    .filter(function (i) { return !i.complete; })
                    .map(function (i) {
                        return new Promise(function (r) { i.onload = i.onerror = r; });
                    })
            )
        ]).then(function () { apply(cachedMm = measure()); });
    });

    // beforeprint fires synchronously before Chrome composites the PDF.
    window.addEventListener('beforeprint', function () { apply(cachedMm || measure()); });
}());
</script>
@endif
</body>
</html>
