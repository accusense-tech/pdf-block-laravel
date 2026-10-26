{{--
  pdf-block::v3.column-flow

  Conteúdo de UMA coluna (ColumnV3.flow). Espelha renderColumnChildren do editor:
  folhas vão DIRETO (sem wrapper) e group/columnSet aninhados são render nativo.
--}}
@php use PdfBlock\Laravel\FlowRender; @endphp
@foreach($flow ?? [] as $node)
  @if($node['meta']['hideOnExport'] ?? false)
    @continue
  @endif
  @if(FlowRender::isColumnSet($node))
    @include('pdf-block::v3.columnset', ['columnset' => $node])
  @elseif(FlowRender::isGroup($node))
    @include('pdf-block::v3.group', ['group' => $node, 'level' => 'structure'])
  @else
    @include('pdf-block::block', ['block' => $node])
  @endif
@endforeach
