@php
  use PdfBlock\Laravel\Email\EmailStyleHelpers as ES;

  // Outer block spacing (padding / margin / borders / background) — applied
  // on the wrapping <td> in structure.blade.php, but we still need it here
  // for any per-block overrides.
  $outerStyle = ES::blockStyles($block['styles'] ?? []);

  // Typography props forwarded to the inliner so every <p>, <h1>-<h6>,
  // <li>, etc. receives these styles directly (not via inheritance).
  $textProps = [
      'fontFamily'    => !empty($block['fontFamily']) ? $block['fontFamily'] : ($fontStack ?? "'Helvetica Neue', Helvetica, Arial, sans-serif"),
      'fontSize'      => $block['fontSize']      ?? 16,
      'fontWeight'    => $block['fontWeight']     ?? 400,
      'fontColor'     => $block['fontColor']      ?? ($fontColor ?? '#333333'),
      'lineHeight'    => $block['lineHeight']     ?? '1.5',
      'textAlign'     => $block['textAlign']      ?? 'left',
      'textTransform' => $block['textTransform']  ?? 'none',
  ];

  $html = $tiptap ? $tiptap->toEmailHtml($block['content'] ?? [], $textProps) : '';
@endphp
{{--
  No wrapper <div>: Outlook (Word engine) and Gmail do not inherit CSS from
  block containers into <p> / <h1>–<h6> children. The inliner above writes
  styles directly onto each element so no wrapper is needed.
  If the block has outer styles (padding, background, etc.) those are handled
  by the parent <td> in structure.blade.php.
--}}
{!! $html !!}
