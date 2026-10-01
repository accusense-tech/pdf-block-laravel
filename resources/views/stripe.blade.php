{{--
  pdf-block::stripe
  
  Renders a StripeBlock — the top-level horizontal band.
  Contains one or more StructureBlocks.
--}}
@php
  use PdfBlock\Laravel\StyleHelpers as S;
  
  $maxW = $stripe['contentMaxWidth'] ?? 0;
  $align = $stripe['contentAlignment'] ?? 'center';
  $innerMargin = match($align) {
      'left'  => '0 auto 0 0',
      'right' => '0 0 0 auto',
      default => '0 auto',
  };
  // Faixa com fundo colorido = card indivisível: não pode ser cortada por uma
  // quebra de página (um fundo partido parece quebrado). Igual a structure.blade.
  $keepTogether = ($stripe['meta']['keepTogether'] ?? false) || S::hasColoredBackground($stripe['styles'] ?? []);
@endphp
<div
  style="{{ S::blockStyles($stripe['styles'] ?? []) }}"
  @if($stripe['meta']['breakBefore'] ?? false) data-break-before="true" @endif
  @if($stripe['meta']['breakAfter'] ?? false) data-break-after="true" @endif
  @if($keepTogether) data-keep-together="true" @endif
>
  <div style="
    {{ $maxW > 0 ? "max-width:{$maxW}px;" : '' }}
    margin:{{ $innerMargin }};
    width:100%;
  ">
    @foreach($stripe['children'] ?? [] as $structure)
      @if($structure['meta']['hideOnExport'] ?? false)
        @continue
      @endif
      @include('pdf-block::structure', ['structure' => $structure])
    @endforeach
  </div>
</div>
