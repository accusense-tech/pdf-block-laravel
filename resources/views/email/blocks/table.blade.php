@php
  use PdfBlock\Laravel\Email\EmailStyleHelpers as ES;
  $outer = ES::blockStyles($block['styles'] ?? []);
  $rows  = $block['rows'] ?? [];
  $headerBg = $block['headerBg'] ?? '#f3f4f6';
  $borderColor = $block['borderColor'] ?? '#e5e7eb';
  $hasHeader = ($block['hasHeader'] ?? true);
@endphp
<table role="presentation" cellpadding="8" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;{{ $outer }}">
  @foreach($rows as $ri => $row)
    @php $isHeader = $hasHeader && $ri === 0; @endphp
    <tr>
      @foreach(($row['cells'] ?? []) as $cell)
        <{{ $isHeader ? 'th' : 'td' }} align="{{ $cell['align'] ?? 'left' }}"
          style="border:1px solid {{ $borderColor }};{{ $isHeader ? 'background:'.$headerBg.';font-weight:700;' : '' }}text-align:{{ $cell['align'] ?? 'left' }};vertical-align:top;">
          {!! $cell['html'] ?? e($cell['text'] ?? '') !!}
        </{{ $isHeader ? 'th' : 'td' }}>
      @endforeach
    </tr>
  @endforeach
</table>
