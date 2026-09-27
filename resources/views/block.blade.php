{{--
  pdf-block::block

  Dispatcher — resolve o renderer do bloco pelo BlockRendererRegistry: built-ins
  pré-registrados via config('pdf-block.blocks') (BladeBlockRenderer) + plugins
  do app. A saída é embrulhada no wrapper de quebras/widows-orphans (meta).
  Tipos sem renderer caem no comentário de diagnóstico (invisível no PDF).
--}}
@php
  // Preenche os campos próprios ausentes com os defaults do tipo (DSL esparsa):
  // fonte única de paridade com a factory/normalização do editor. No-op p/ plugins.
  $block = \PdfBlock\Laravel\BlockDefaults::fill($block);
  $__type = $block['type'] ?? '';
  $__meta = $block['meta'] ?? [];
  $__registry = app(\PdfBlock\Laravel\BlockRendererRegistry::class);
  $__html = $__registry->render('pdf', $block, [
      'tiptap' => $tiptap ?? null,
      'mode'   => 'pdf',
      'data'   => $data ?? [],
  ]);
  $__hasBreakAttr = ($__meta['breakBefore'] ?? false) || ($__meta['breakAfter'] ?? false) || ($__meta['keepTogether'] ?? false);
  // widows/orphans por bloco (P8/D): inline → herdado pelos parágrafos internos,
  // sobrescrevendo o default do .pdfb-content-area.
  $__frag = '';
  if (is_numeric($__meta['orphans'] ?? null)) $__frag .= 'orphans:' . max(1, (int) $__meta['orphans']) . ';';
  if (is_numeric($__meta['widows'] ?? null))  $__frag .= 'widows:' . max(1, (int) $__meta['widows']) . ';';
  // Alvo de âncora de clique: emite `id="<blockId>"` p/ o link interno (#id) funcionar.
  $__isAnchor = isset($block['id']) && in_array($block['id'], $pdfbAnchorTargets ?? [], true);
  $__needWrap = $__hasBreakAttr || $__frag !== '' || $__isAnchor;
@endphp
@if($__needWrap)
<div
  @if($__isAnchor) id="{{ $block['id'] }}" @endif
  @if($__meta['breakBefore'] ?? false) data-break-before="true" @endif
  @if($__meta['breakAfter'] ?? false) data-break-after="true" @endif
  @if($__meta['keepTogether'] ?? false) data-keep-together="true" @endif
  @if($__frag !== '') style="{{ $__frag }}" @endif
>
@endif
@if($__html !== null)
  {!! $__html !!}
@else
  {{-- Tipo não reconhecido: nem plugin registrado, nem bloco built-in. Marcador
       inofensivo (comentário HTML) p/ diagnóstico — invisível no PDF final. --}}
  <!-- pdf-block: tipo desconhecido "{{ preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $__type) }}" -->
@endif
@if($__needWrap)
</div>
@endif
