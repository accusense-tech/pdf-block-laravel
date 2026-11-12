@php
  $color = $block['color'] ?? '#e5e7eb';
  $thickness = (int) ($block['thickness'] ?? 1);
  $padV = (int) ($block['paddingV'] ?? 8);
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="padding:{{ $padV }}px 0;">
  <tr>
    <td height="{{ $thickness }}" style="font-size:0;line-height:0;background:{{ $color }};border-collapse:collapse;">&nbsp;</td>
  </tr>
</table>
