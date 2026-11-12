{{--
  pdf-block::v3.stripe-flow

  Fluxo de uma Seção → faixas de topo (espelha flowToStripes / renderStripeFlow):
  folhas soltas consecutivas viram um wrapper sintético (stripe→structure→coluna),
  group/columnSet são render nativo. `firstBreak` põe data-break-before na 1ª faixa
  (seções após a 1ª começam em nova página — page setup compartilhado).
--}}
@php
  use PdfBlock\Laravel\FlowRender;
  use PdfBlock\Laravel\StyleHelpers as S;
  $runs = FlowRender::runs($flow ?? []);
  $wrap = S::blockStyles(FlowRender::defaultStyles());
@endphp
@foreach($runs as $run)
  @php $brk = ($firstBreak ?? false) && $loop->first; @endphp
  @if($run['kind'] === 'leaves')
    {{-- folhas soltas → stripe → structure(1 col) sintéticos (defaults = v2) --}}
    <div style="{{ $wrap }}" @if($brk) data-break-before="true" @endif>
      <div style="margin:0 auto;width:100%;">
        <div style="{{ $wrap }}">
          <div style="display:grid;grid-template-columns:minmax(0, 100fr);gap:0px;align-items:start;width:100%;">
            <div style="flex:100 0 0px;min-width:0;{{ $wrap }}">
              @foreach($run['items'] as $leaf)
                @include('pdf-block::block', ['block' => $leaf])
              @endforeach
            </div>
          </div>
        </div>
      </div>
    </div>
  @elseif($run['kind'] === 'columnSet')
    <div style="{{ $wrap }}" @if($brk) data-break-before="true" @endif>
      <div style="margin:0 auto;width:100%;">
        @include('pdf-block::v3.columnset', ['columnset' => $run['node']])
      </div>
    </div>
  @else
    @include('pdf-block::v3.group', ['group' => $run['node'], 'level' => 'stripe', 'forceBreak' => $brk])
  @endif
@endforeach
