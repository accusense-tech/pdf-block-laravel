{{-- pdf-block::email.stripe --}}
@php
  use PdfBlock\Laravel\Email\EmailStyleHelpers as ES;
  $stripeStyles = ES::blockStyles($stripe['styles'] ?? []) . \PdfBlock\Laravel\FlowRender::typography($stripe['typography'] ?? null);
  $stripeBg     = ES::solidFallbackColor(($stripe['styles']['background'] ?? ['type' => 'solid', 'color' => 'transparent']));
  $align        = ES::alignAttr($stripe['contentAlignment'] ?? 'center');
  $maxW         = (int) ($stripe['contentMaxWidth'] ?? 0);
  $innerWidth   = $maxW > 0 ? min($maxW, $width) : $width;
@endphp
<tr>
  <td align="{{ $align }}" style="background:{{ $stripeBg }};{{ $stripeStyles }}">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="{{ $innerWidth }}" style="width:{{ $innerWidth }}px;max-width:{{ $innerWidth }}px;">
      @foreach($stripe['children'] ?? [] as $structure)
        @if($structure['meta']['hideOnExport'] ?? false)
          @continue
        @endif
        @include('pdf-block::email.structure', ['structure' => $structure, 'width' => $innerWidth, 'fontStack' => $fontStack, 'fontColor' => $fontColor])
      @endforeach
    </table>
  </td>
</tr>
