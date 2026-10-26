<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Email;

/**
 * VML (Vector Markup Language) fallbacks for Microsoft Outlook on Windows.
 *
 * Outlook 2007-2019 uses the Word rendering engine, which ignores most modern
 * CSS. VML is an old Microsoft-only vector format that Word *does* understand
 * and that we wrap in `<!--[if mso]> … <![endif]-->` conditional comments so
 * other clients skip it entirely.
 *
 * The snippets produced here are designed to live alongside the standard HTML
 * fallback — typical usage is:
 *
 *   {!! VmlHelpers::buttonRoundrect($href, $text, [...]) !!}
 *   <!--[if !mso]><!-- -->
 *   <a href="…" style="…">…</a>
 *   <!--<![endif]-->
 */
class VmlHelpers
{
    /**
     * Render a button as a VML rounded-rectangle with a centered anchor.
     *
     * @param  array{
     *   width?: int, height?: int, radius?: int, fill?: string,
     *   stroke?: string, strokeWeight?: string,
     *   fontFamily?: string, fontSize?: int, fontWeight?: string, color?: string,
     * }  $opts
     */
    public static function buttonRoundrect(string $href, string $text, array $opts = []): string
    {
        $width        = (int) ($opts['width'] ?? 200);
        $height       = (int) ($opts['height'] ?? 40);
        $radius       = (int) ($opts['radius'] ?? 4);
        $fill         = (string) ($opts['fill'] ?? '#2563eb');
        $stroke       = (string) ($opts['stroke'] ?? $fill);
        $strokeWeight = (string) ($opts['strokeWeight'] ?? '1px');
        $fontFamily   = (string) ($opts['fontFamily'] ?? 'Arial, sans-serif');
        $fontSize     = (int) ($opts['fontSize'] ?? 14);
        $fontWeight   = (string) ($opts['fontWeight'] ?? 'bold');
        $color        = (string) ($opts['color'] ?? '#ffffff');

        $href = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
    href="{$href}" style="height:{$height}px;v-text-anchor:middle;width:{$width}px;" arcsize="{$radius}%"
    stroke="t" strokecolor="{$stroke}" strokeweight="{$strokeWeight}" fillcolor="{$fill}">
  <w:anchorlock/>
  <center style="color:{$color};font-family:{$fontFamily};font-size:{$fontSize}px;font-weight:{$fontWeight};">{$text}</center>
</v:roundrect>
<![endif]-->
HTML;
    }

    /**
     * Render a full-bleed background image for Outlook using VML `rect` with
     * `v:fill`. Consumers place the HTML fallback inside the returned wrapper.
     *
     * Usage:
     *   echo VmlHelpers::backgroundRectOpen($url, 600, 240);
     *   echo '<div style="…">actual content</div>';
     *   echo VmlHelpers::backgroundRectClose();
     */
    public static function backgroundRectOpen(string $imageUrl, int $width, int $height, string $fallbackColor = '#f0f0f0'): string
    {
        $url = htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!--[if mso]>
<v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false" style="width:{$width}px;height:{$height}px;">
  <v:fill type="frame" src="{$url}" color="{$fallbackColor}" />
  <v:textbox inset="0,0,0,0">
<![endif]-->
<div>
HTML;
    }

    public static function backgroundRectClose(): string
    {
        return <<<HTML
</div>
<!--[if mso]></v:textbox></v:rect><![endif]-->
HTML;
    }
}
