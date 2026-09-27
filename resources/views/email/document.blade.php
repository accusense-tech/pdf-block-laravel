{{--
  pdf-block::email.document

  Email-safe outer shell. Table-based layout, inline styles, with MSO
  conditional comments for Outlook Windows.

  Baseline target: Outlook 2007+, Gmail (web + mobile), Apple Mail, OWA.

  IMPORTANT: no external resources (fonts, JS). All CSS is either inline
  (preferred) or in the `<style>` block below which Gmail may strip — keep
  it to resets + MSO hacks only.
--}}
@php
  $meta        = $doc['meta'] ?? [];
  $emailCfg    = $doc['emailSettings'] ?? [];
  $globals     = $doc['globalStyles'] ?? [];
  $width       = (int) ($emailCfg['width'] ?? 600);
  $pageBg      = $emailCfg['pageBackground'] ?? $globals['pageBackground'] ?? '#f3f3f3';
  $bodyBg      = $emailCfg['bodyBackground'] ?? $globals['contentBackground'] ?? '#ffffff';
  $fontColor   = $globals['defaultFontColor'] ?? '#333333';
  $fontStack   = $emailCfg['fontStack'] ?? "'Helvetica Neue', Helvetica, Arial, sans-serif";
  $preheader   = $emailCfg['preheader'] ?? '';
  $title       = $meta['title'] ?? '';
  $locale      = $meta['locale'] ?? 'pt-BR';
@endphp
@if($wrap)<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="{{ $locale }}">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="x-apple-disable-message-reformatting" />
  <meta name="color-scheme" content="light" />
  <meta name="supported-color-schemes" content="light" />
  <title>{{ e($title) }}</title>
  <!--[if gte mso 9]>
  <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
  </xml>
  <![endif]-->
  <style type="text/css">
    /* Resets */
    body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; display:block; }
    body { margin:0 !important; padding:0 !important; width:100% !important; background:{{ $pageBg }}; }
    a { color:inherit; }
    /* Mobile */
    @media only screen and (max-width: {{ $width }}px) {
      .pdfb-e-container { width:100% !important; }
      .pdfb-e-col { display:block !important; width:100% !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:{{ $pageBg }};font-family:{{ $fontStack }};color:{{ $fontColor }};">
@if($preheader !== '')
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{{ $pageBg }};">
  {{ $preheader }}
</div>
@endif
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:{{ $pageBg }};">
  <tr>
    <td align="center" style="padding:0;">
      <!--[if mso | IE]>
      <table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="{{ $width }}"><tr><td>
      <![endif]-->
      <table role="presentation" class="pdfb-e-container" cellpadding="0" cellspacing="0" border="0" width="{{ $width }}" style="width:{{ $width }}px;max-width:{{ $width }}px;background:{{ $bodyBg }};">
        @foreach($doc['blocks'] ?? [] as $stripe)
          @if($stripe['meta']['hideOnExport'] ?? false)
            @continue
          @endif
          @include('pdf-block::email.stripe', ['stripe' => $stripe, 'width' => $width, 'fontStack' => $fontStack, 'fontColor' => $fontColor])
        @endforeach
      </table>
      <!--[if mso | IE]>
      </td></tr></table>
      <![endif]-->
    </td>
  </tr>
</table>
</body>
</html>
@else
{{-- Fragment mode: only emit the rows. Consumer wraps in their own layout. --}}
<table role="presentation" class="pdfb-e-container" cellpadding="0" cellspacing="0" border="0" width="{{ $width }}" style="width:{{ $width }}px;max-width:{{ $width }}px;background:{{ $bodyBg }};font-family:{{ $fontStack }};color:{{ $fontColor }};">
  @foreach($doc['blocks'] ?? [] as $stripe)
    @if($stripe['meta']['hideOnExport'] ?? false)
      @continue
    @endif
    @include('pdf-block::email.stripe', ['stripe' => $stripe, 'width' => $width, 'fontStack' => $fontStack, 'fontColor' => $fontColor])
  @endforeach
</table>
@endif
