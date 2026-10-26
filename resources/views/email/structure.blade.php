{{--
  pdf-block::email.structure

  Multi-column row using the "hybrid/spongy" technique:
  - Outlook sees a ghost table via MSO conditional comments (true columns).
  - Everything else sees inline-block divs that collapse to full width on
    narrow viewports via the media query in document.blade.php.
--}}
@php
  use PdfBlock\Laravel\Email\EmailStyleHelpers as ES;
  $structureStyles = ES::blockStyles($structure['styles'] ?? []);
  // Fonte herdada do container (`typography`) — mesma regra do PDF (FlowRender).
  $structureStyles .= \PdfBlock\Laravel\FlowRender::typography($structure['typography'] ?? null);
  $columns = $structure['columns'] ?? [];
  $gap     = (int) ($structure['columnGap'] ?? 0);
@endphp
<tr>
  <td style="{{ $structureStyles }}">
    <!--[if mso | IE]>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="{{ $width }}"><tr>
    <![endif]-->
    @foreach($columns as $i => $column)
      @php
        $pct     = (float) ($column['width'] ?? (100 / max(1, count($columns))));
        $colPx   = (int) floor(($width * $pct) / 100);
        $colSty  = ES::blockStyles($column['styles'] ?? []) . \PdfBlock\Laravel\FlowRender::typography($column['typography'] ?? null);
        $isLast  = $i === count($columns) - 1;
        $padRight = (!$isLast && $gap > 0) ? $gap : 0;
      @endphp
      <!--[if mso | IE]>
      <td width="{{ $colPx }}" valign="top" style="width:{{ $colPx }}px;padding-right:{{ $padRight }}px;">
      <![endif]-->
      <div class="pdfb-e-col" style="display:inline-block;vertical-align:top;width:{{ $colPx }}px;max-width:{{ $colPx }}px;{{ $colSty }}">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;">
          @foreach($column['children'] ?? [] as $block)
            @if($block['meta']['hideOnExport'] ?? false)
              @continue
            @endif
            <tr><td>
              @include('pdf-block::email.block', ['block' => $block, 'fontStack' => $fontStack, 'fontColor' => $fontColor])
            </td></tr>
          @endforeach
        </table>
      </div>
      <!--[if mso | IE]>
      </td>
      <![endif]-->
    @endforeach
    <!--[if mso | IE]>
    </tr></table>
    <![endif]-->
  </td>
</tr>
