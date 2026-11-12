{{--
  pdf-block::v3.structure-flow

  Fluxo DENTRO de um Group de topo → estruturas (espelha flowToStructures /
  renderStructureFlow): folhas soltas viram structure(1 col) sintética; group/
  columnSet são render nativo.
--}}
@php
  use PdfBlock\Laravel\FlowRender;
  use PdfBlock\Laravel\StyleHelpers as S;
  $runs = FlowRender::runs($flow ?? []);
  $wrap = S::blockStyles(FlowRender::defaultStyles());
@endphp
@foreach($runs as $run)
  @if($run['kind'] === 'leaves')
    <div style="{{ $wrap }}">
      <div style="display:grid;grid-template-columns:minmax(0, 100fr);gap:0px;align-items:start;width:100%;">
        <div style="flex:100 0 0px;min-width:0;{{ $wrap }}">
          @foreach($run['items'] as $leaf)
            @include('pdf-block::block', ['block' => $leaf])
          @endforeach
        </div>
      </div>
    </div>
  @elseif($run['kind'] === 'columnSet')
    @include('pdf-block::v3.columnset', ['columnset' => $run['node']])
  @else
    @include('pdf-block::v3.group', ['group' => $run['node'], 'level' => 'structure'])
  @endif
@endforeach
