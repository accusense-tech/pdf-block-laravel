@php
  use PdfBlock\Laravel\StyleHelpers as S;

  $style = S::blockStyles($block['styles'] ?? []);
  $rows = $block['rows'] ?? [];
  $hasHeader = $block['headerRow'] ?? false;
  $borderW = $block['borderWidth'] ?? 1;
  $borderC = $block['borderColor'] ?? '#e0e0ec';
  $borderCSS = $borderW > 0
      ? "border:{$borderW}px solid {$borderC};"
      : "border:none;";
@endphp
<div style="{{ $style }}">
  <table style="
    @php $fs = $block['fontSize'] ?? 0; @endphp
    @if($fs > 0)font-size:{{ $fs }}px;@endif
    @if(!empty($block['fontColor']))color:{{ $block['fontColor'] }};@endif
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
  ">
    @if(!empty($block['columnWidths']))
      <colgroup>
        @foreach($block['columnWidths'] as $w)
          <col style="@if($w)width:{{ $w }}%;@endif" />
        @endforeach
      </colgroup>
    @endif
    <tbody>
      @foreach($rows as $ri => $row)
        @php
          $isHeader = $ri === 0 && $hasHeader;
          $isStriped = ($block['stripedRows'] ?? false)
              && !$isHeader
              && $ri % 2 === ($hasHeader ? 0 : 1);
          $rowBg = $isHeader
              ? ($block['headerBgColor'] ?? '#f0f0f5')
              : ($isStriped ? ($block['stripedColor'] ?? '#f8f9fd') : 'transparent');
        @endphp
        <tr style="background-color:{{ $rowBg }}">
          @foreach($row as $cell)
            @php $align = $block['columnAligns'][$loop->index] ?? null; @endphp
            @if($isHeader)
              <th style="
                {{ $borderCSS }}
                padding:{{ $block['cellPadding'] ?? 10 }}px;
                color:{{ $block['headerFontColor'] ?? 'inherit' }};
                font-weight:600;
                @if($align)text-align:{{ $align }};@endif
                vertical-align:top;
                word-break:break-word;
                white-space:pre-wrap;
              ">{{ $cell }}</th>
            @else
              <td style="
                {{ $borderCSS }}
                padding:{{ $block['cellPadding'] ?? 10 }}px;
                color:{{ $block['fontColor'] ?? 'inherit' }};
                @if($align)text-align:{{ $align }};@endif
                vertical-align:top;
                word-break:break-word;
                white-space:pre-wrap;
              ">{{ $cell }}</td>
            @endif
          @endforeach
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
