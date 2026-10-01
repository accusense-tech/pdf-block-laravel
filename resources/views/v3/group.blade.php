{{--
  pdf-block::v3.group

  Group v3 NATIVO. level='stripe' (filho de Seção, espelha groupToStripe) ou
  level='structure' (aninhado em coluna/grupo, espelha groupToStructure).
  Variante banner: fundo/overlay/minHeight a partir de group.banner.
  `forceBreak` = 1ª faixa de uma seção não-primeira (nova página).
--}}
@php
  use PdfBlock\Laravel\FlowRender;
  use PdfBlock\Laravel\StyleHelpers as S;

  $isBanner = ($group['variant'] ?? 'default') === 'banner';
  // DISPOSIÇÃO (`row`): o conteúdo do grupo vira flexbox — lado a lado
  // (direction 'row') ou empilhado com distribuição (direction 'column').
  // Espelho de core/rowLayout.ts + FlowCanvas. Sem `row`, é o fluxo normal.
  $row = is_array($group['row'] ?? null) ? $group['row'] : null;
  $rowCss = FlowRender::rowCss($row);
  // FONTE herdada pelo conteúdo do grupo (`typography`) — espelho de
  // core/typography.ts. Vai no MESMO elemento que os estilos do grupo.
  $typoCss = FlowRender::typography($group['typography'] ?? null);
  $meta = $group['meta'] ?? [];
  $gStyles = $group['styles'] ?? [];
  $keepTogether = ($meta['keepTogether'] ?? false) || S::hasColoredBackground($gStyles);
  $forceBreak = $forceBreak ?? false;
  $wrap = S::blockStyles(FlowRender::defaultStyles());
  // ESTICAR (só aninhado; no topo o grupo já ocupa tudo). Espelho de
  // core/fillHeight.ts + FlowCanvas: `fillWidth` toma a largura livre do
  // container lado a lado, `fillHeight` acompanha a altura.
  $fillW = $level !== 'stripe' && FlowRender::wantsFillWidth($group) ? FlowRender::FILL_WIDTH_CSS : '';
  $fillH = $level !== 'stripe' && FlowRender::wantsFillHeight($group) ? FlowRender::FILL_CHILD_CSS : '';
  // Alvo de âncora de clique (#id). O group recursiona stripe→structure; `anchorEmitted`
  // garante que o `id` saia UMA vez só (no wrapper externo), evitando id duplicado.
  $__isAnchor = isset($group['id']) && in_array($group['id'], $pdfbAnchorTargets ?? [], true);
  $anchorEmitted = $anchorEmitted ?? false;
@endphp

@if($level === 'stripe')
  @if($isBanner)
    {{-- banner no topo: stripe sintético (default) embrulha a structure banner --}}
    <div style="{{ $wrap }}" @if($__isAnchor) id="{{ $group['id'] }}" @endif @if($forceBreak) data-break-before="true" @endif>
      <div style="margin:0 auto;width:100%;">
        @include('pdf-block::v3.group', ['group' => $group, 'level' => 'structure', 'anchorEmitted' => $__isAnchor])
      </div>
    </div>
  @else
    @php
      $maxW = $group['contentMaxWidth'] ?? 0;
      $align = $group['contentAlignment'] ?? 'center';
      $innerMargin = match($align) { 'left' => '0 auto 0 0', 'right' => '0 0 0 auto', default => '0 auto' };
    @endphp
    <div style="{{ S::blockStyles($gStyles) }}{{ $typoCss }}{{ $fillH }}{{ $fillW }}"
      @if($__isAnchor && !$anchorEmitted) id="{{ $group['id'] }}" @endif
      @if(($meta['breakBefore'] ?? false) || $forceBreak) data-break-before="true" @endif
      @if($meta['breakAfter'] ?? false) data-break-after="true" @endif
      @if($keepTogether) data-keep-together="true" @endif
      @if($rowCss !== '') data-atomic="true" @endif
    >
      <div style="{{ $maxW > 0 ? "max-width:{$maxW}px;" : '' }}margin:{{ $innerMargin }};width:100%;{{ $rowCss }}">
        @if($rowCss !== '')
          @include('pdf-block::v3.column-flow', ['flow' => $group['flow'] ?? []])
        @else
          @include('pdf-block::v3.structure-flow', ['flow' => $group['flow'] ?? []])
        @endif
      </div>
    </div>
  @endif
@else
  {{-- level === 'structure' (aninhado) --}}
  @if($isBanner)
    @php
      $banner = array_merge(\PdfBlock\Laravel\BlockDefaults::bannerFields(), $group['banner'] ?? []);
      $bgImg = $banner['backgroundImage'] ?? '';
      $bgSize = $banner['backgroundSize'] ?? 'cover';
      $bgPos = $banner['backgroundPosition'] ?? 'center center';
      $minH = $banner['minHeight'] ?? 300;
      $overlayColor = $banner['overlayColor'] ?? '#000000';
      $overlayOpacity = $banner['overlayOpacity'] ?? 0;
      $outerStyle = S::blockStyles($gStyles) . $typoCss;
      $outerStyle .= "position:relative;min-height:{$minH}px;overflow:hidden;";
      // ESTICAR: cresce na coluna flex do pai em vez de parar na min-height.
      $outerStyle .= $fillH . $fillW;
      // AJUSTE da imagem de fundo (recorte/cor/zoom): com ele a imagem vira uma
      // CAMADA própria — recortá-la numa curva não pode levar o texto junto.
      // Espelha o FlowCanvas (bannerBgLayer).
      $adjust = is_array($banner['adjust'] ?? null) ? $banner['adjust'] : null;
      // Só ladrilha quando size='auto' (Mosaico); senão no-repeat — o default do
      // CSS é `repeat`, e sem isto 'contain' viraria mosaico. No mosaico o
      // tamanho vem em % da caixa (ver S::bannerBackgroundSize): o `auto` do CSS
      // é o tamanho NATURAL da imagem e não repetiria nada numa foto grande.
      $bgRepeat = S::bannerBackgroundRepeat($bgSize);
      $bgSizeCss = S::bannerBackgroundSize($bgSize, $banner['backgroundTileSize'] ?? null);
      // SEMPRE em camada: como background do container a imagem emitia um segundo
      // `background-image` na mesma regra que o GRADIENTE de `styles` ocupa — a
      // última vence e o gradiente sumia. Ver structure.blade.php e o FlowCanvas.
      $bgLayer = $bgImg !== '';
      $bgLayerStyle = '';
      if ($bgLayer) {
          $focus = \PdfBlock\Laravel\ImageAdjust::focus($adjust);
          // `cssUrl(safeUrl())`, NUNCA `e()`: este bloco vai para um atributo
          // `style="{{ }}"`, que já escapa — o `e()` era um segundo escape e
          // fazia `?X-Amz-Algorithm=…&X-Amz-Expires=…` chegar ao navegador como
          // `&amp;`, invalidando a assinatura. Todo banner com fundo servido por
          // presigned S3 ou CDN com query string saía sem imagem no PDF. É o
          // mesmo par que `StyleHelpers::backgroundToCSS` usa; ver o teste
          // `background_url_escape_check.php`, que cobre os dois caminhos.
          $bgLayerStyle = 'position:absolute;inset:0;z-index:0;'
              . 'background-image:url(' . S::cssUrl(S::safeUrl($bgImg, true)) . ');'
              . "background-size:{$bgSizeCss};background-repeat:{$bgRepeat};"
              . 'background-position:' . ($focus !== '' ? $focus : $bgPos) . ';'
              . \PdfBlock\Laravel\ImageAdjust::css($adjust);
      }
    @endphp
    <div style="{{ $outerStyle }}"
      @if($__isAnchor && !$anchorEmitted) id="{{ $group['id'] }}" @endif
      @if($meta['breakBefore'] ?? false) data-break-before="true" @endif
      @if($meta['breakAfter'] ?? false) data-break-after="true" @endif
      @if($meta['keepTogether'] ?? false) data-keep-together="true" @endif
      data-atomic="true"
    >
      @if($bgLayer)
        <div style="{{ $bgLayerStyle }}"></div>
      @endif
      @if(($overlayOpacity ?? 0) > 0)
        <div style="position:absolute;inset:0;background-color:{{ $overlayColor }};opacity:{{ $overlayOpacity }};z-index:0;"></div>
      @endif
      <div style="display:grid;grid-template-columns:minmax(0, 100fr);gap:0px;align-items:{{ FlowRender::vAlign($group['verticalAlignment'] ?? 'center') }};position:relative;z-index:1;min-height:{{ $minH }}px;width:100%;{{ $fillH !== '' ? 'flex:1 1 auto;' : '' }}">
        <div style="flex:100 0 0px;min-width:0;{{ $wrap }}">
          @include('pdf-block::v3.column-flow', ['flow' => $group['flow'] ?? []])
        </div>
      </div>
    </div>
  @else
    @php
      $hasColoredBg = S::hasColoredBackground($gStyles);
      // Largura/alinhamento do CONTEÚDO valem em QUALQUER nível (paridade com o
      // FlowCanvas): antes só o grupo de topo os aplicava.
      $maxW = $group['contentMaxWidth'] ?? 0;
      $align = $group['contentAlignment'] ?? 'center';
      $innerMargin = match($align) { 'left' => '0 auto 0 0', 'right' => '0 0 0 auto', default => '0 auto' };
      $contentBox = ($maxW > 0 ? "max-width:{$maxW}px;margin:{$innerMargin};" : '');
    @endphp
    <div style="{{ S::blockStyles($gStyles) }}{{ $typoCss }}{{ $fillH }}{{ $fillW }}"
      @if($__isAnchor && !$anchorEmitted) id="{{ $group['id'] }}" @endif
      @if($meta['breakBefore'] ?? false) data-break-before="true" @endif
      @if($meta['breakAfter'] ?? false) data-break-after="true" @endif
      @if($meta['keepTogether'] ?? false) data-keep-together="true" @endif
      @if($hasColoredBg || $rowCss !== '') data-atomic="true" @endif
    >
      <div style="display:grid;grid-template-columns:minmax(0, 100fr);gap:0px;align-items:start;width:100%;{{ $fillH !== '' ? 'flex:1 1 auto;' : '' }}">
        <div style="flex:100 0 0px;min-width:0;{{ $wrap }}{{ $contentBox }}{{ $rowCss === '' && FlowRender::hasFillHeightChild($group['flow'] ?? null) ? FlowRender::FILL_PARENT_CSS : '' }}{{ $rowCss }}">
          @include('pdf-block::v3.column-flow', ['flow' => $group['flow'] ?? []])
        </div>
      </div>
    </div>
  @endif
@endif
