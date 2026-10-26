{{--
  pdf-block::v3.document-body

  Corpo do documento v3 NATIVO (Seções/fluxo) — sem achatar para v2.
  Cada Seção após a 1ª começa em nova página (data-break-before na 1ª faixa).
  No modo paginado (P8/A2) cada seção é envolvida num wrapper com `page: <nome>`,
  fazendo o Chromium aplicar a @page NOMEADA daquela seção (header/footer próprios).
  A troca de nome entre seções também força a quebra de página (redundante com o
  data-break-before — sem página em branco, comprovado).
--}}
@foreach($sections ?? [] as $section)
  @php $secPage = \PdfBlock\Laravel\StyleHelpers::pageName((string) ($section['id'] ?? $loop->index)); @endphp
  @if($paginated ?? false)
    <div style="page: {{ $secPage }};">
      @include('pdf-block::v3.stripe-flow', [
        'flow' => $section['flow'] ?? [],
        'firstBreak' => ! $loop->first,
      ])
    </div>
  @else
    @include('pdf-block::v3.stripe-flow', [
      'flow' => $section['flow'] ?? [],
      'firstBreak' => ! $loop->first,
    ])
  @endif
@endforeach
