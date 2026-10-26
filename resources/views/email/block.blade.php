{{--
  pdf-block::email.block

  Dispatcher do modo e-mail. Resolve o renderer pelo BlockRendererRegistry:
  built-ins pré-registrados via config('pdf-block.blocks').email + plugins do
  app. Tipos sem forma em e-mail (pagebreak/chart/svg) mapeiam para uma view
  vazia; tipos desconhecidos caem no comentário de diagnóstico (invisível).
--}}
@php
  // Preenche os campos próprios ausentes com os defaults do tipo (DSL esparsa) —
  // mesma fonte única do PDF (BlockDefaults), garantindo paridade editor↔e-mail.
  $block = \PdfBlock\Laravel\BlockDefaults::fill($block);
  $__type = $block['type'] ?? '';
  $__registry = app(\PdfBlock\Laravel\BlockRendererRegistry::class);
  $__html = $__registry->render('email', $block, [
      'tiptap'    => $tiptap ?? null,
      'mode'      => 'email',
      'fontStack' => $fontStack ?? null,
      'fontColor' => $fontColor ?? null,
      'data'      => $data ?? [],
  ]);
@endphp
@if($__html !== null)
  {!! $__html !!}
@else
  {{-- Tipo não reconhecido: nem plugin registrado, nem bloco built-in. Marcador
       inofensivo (comentário HTML) p/ diagnóstico — invisível no cliente. --}}
  <!-- pdf-block: tipo desconhecido "{{ preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $__type) }}" -->
@endif
