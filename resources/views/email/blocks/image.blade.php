@php
  use PdfBlock\Laravel\Email\EmailStyleHelpers as ES;
  $style = ES::blockStyles($block['styles'] ?? []);
  $align = $block['alignment'] ?? 'center';
  $src   = $block['src'] ?? '';
  $alt   = $block['alt'] ?? '';
  $link  = $block['link'] ?? null;
  $w     = $block['width'] ?? null;
  $imgStyle = 'display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;'
            . ($w ? "width:{$w}px;" : '');
@endphp
{{--
  Use <table> instead of <div> for alignment — Outlook (Word engine) ignores
  `text-align` on a <div> but respects it on a <td> via the `align` attribute.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="{{ $style }}">
  <tr>
    <td align="{{ $align }}" style="text-align:{{ $align }};">
      @if($link)<a href="{{ e($link) }}" target="_blank" style="text-decoration:none;border:0;">@endif
      <img src="{{ e($src) }}" alt="{{ e($alt) }}" @if($w) width="{{ $w }}" @endif style="{{ $imgStyle }}" />
      @if($link)</a>@endif
    </td>
  </tr>
</table>
