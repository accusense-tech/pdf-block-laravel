<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Email;

/**
 * Post-processes TipTap-generated HTML for email-safe delivery.
 *
 * Email clients — especially Outlook (Word rendering engine), Gmail (strips
 * <style> and ignores CSS inheritance), and Yahoo Mail — require that every
 * typographic property is declared directly on each block-level element via
 * an inline `style` attribute. CSS inheritance from a parent <div> or <td>
 * is NOT reliable.
 *
 * Rules applied:
 *  - <p>        → full typography reset (font, size, weight, color, line-height,
 *                  text-align, text-transform) + margin reset
 *  - <h1>–<h6>  → same as <p> + heading-appropriate font-size/weight defaults
 *  - <ul>/<ol>  → margin reset + padding-left
 *  - <li>       → full typography + margin
 *  - <blockquote> → left-border treatment + padding
 *  - <a>        → color + text-decoration (Gmail strips inherited color)
 *  - <strong>   → explicit font-weight:700
 *  - <em>       → explicit font-style:italic
 *  - <u>        → explicit text-decoration:underline
 *  - <s>/<strike> → explicit text-decoration:line-through
 *
 * References:
 *  - https://www.caniemail.com/
 *  - https://www.emailonacid.com/blog/article/email-development/
 *  - Litmus Email Client CSS Support Guide
 *  - https://templates.mailchimp.com/development/html/
 */
class EmailHtmlInliner
{
    /**
     * Heading sizes in pixels (browser-like defaults so content looks
     * reasonable even when the block has no explicit fontSize).
     */
    private const HEADING_SIZES = [
        'h1' => 28,
        'h2' => 24,
        'h3' => 20,
        'h4' => 18,
        'h5' => 16,
        'h6' => 14,
    ];

    /**
     * @param  string  $html        Raw HTML from TipTap / tiptap-php
     * @param  array   $textProps   Block-level typography from the DSL node:
     *                              fontSize (int px), fontWeight (int/string),
     *                              fontColor (string), lineHeight (string|float),
     *                              textAlign (string), textTransform (string),
     *                              fontFamily (string — full CSS stack)
     * @return string               HTML with inline styles on every block element
     */
    public static function inline(string $html, array $textProps = []): string
    {
        if (trim($html) === '') {
            return $html;
        }

        // ---------------------------------------------------------------
        // Resolve props with sensible defaults
        // ---------------------------------------------------------------
        $fontFamily    = $textProps['fontFamily']    ?? "'Helvetica Neue', Helvetica, Arial, sans-serif";
        $fontSize      = isset($textProps['fontSize'])    ? (int) $textProps['fontSize']    : 16;
        $fontWeight    = isset($textProps['fontWeight'])  ? (string) $textProps['fontWeight'] : '400';
        $fontColor     = $textProps['fontColor']     ?? '#333333';
        $lineHeight    = isset($textProps['lineHeight'])  ? (string) $textProps['lineHeight']  : '1.5';
        $textAlign     = $textProps['textAlign']     ?? 'left';
        $textTransform = $textProps['textTransform'] ?? 'none';

        // ---------------------------------------------------------------
        // Parse as HTML fragment via DOMDocument
        // ---------------------------------------------------------------
        $doc = new \DOMDocument('1.0', 'UTF-8');

        // Wrap in a container so DOMDocument doesn't add <html>/<body> automatically.
        // The charset meta forces UTF-8 interpretation.
        $wrapped = '<?xml encoding="UTF-8">'
                 . '<div id="__pdfb_root__">' . $html . '</div>';

        // Suppress HTML5 warnings (DOMDocument is strict HTML4).
        libxml_use_internal_errors(true);
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $doc->getElementById('__pdfb_root__');
        if (!$root) {
            return $html;
        }

        $xpath = new \DOMXPath($doc);

        // ---------------------------------------------------------------
        // Helper: merge new styles onto existing style attribute
        // Existing per-node inline styles (e.g. color from TipTap marks)
        // come AFTER our base styles so they win.
        // ---------------------------------------------------------------
        $applyStyle = static function (\DOMElement $el, string $newStyle) use ($doc): void {
            $existing = $el->getAttribute('style');
            // Strip trailing semicolons then join
            $combined = rtrim($newStyle, '; ') . ';'
                      . (trim($existing) !== '' ? ' ' . ltrim($existing) : '');
            $el->setAttribute('style', $combined);
        };

        // ---------------------------------------------------------------
        // Base typography string — shared by p, li, headings
        // ---------------------------------------------------------------
        $baseTypography = self::buildBaseTypography(
            $fontFamily, $fontSize, $fontWeight, $fontColor, $lineHeight,
            $textAlign, $textTransform
        );

        // ---------------------------------------------------------------
        // <p> — full reset + typography
        // ---------------------------------------------------------------
        foreach ($xpath->query('.//p', $root) as $el) {
            /** @var \DOMElement $el */
            $style = 'margin:0 0 10px 0;' . $baseTypography;
            $applyStyle($el, $style);
        }

        // ---------------------------------------------------------------
        // <h1>–<h6> — heading-specific size/weight, inherit color + family
        // ---------------------------------------------------------------
        foreach (self::HEADING_SIZES as $tag => $defaultSize) {
            foreach ($xpath->query('.//' . $tag, $root) as $el) {
                /** @var \DOMElement $el */
                $hStyle = 'margin:0 0 10px 0;'
                        . "font-family:{$fontFamily};"
                        . "font-size:{$defaultSize}px;"
                        . 'font-weight:700;'
                        . "color:{$fontColor};"
                        . 'line-height:1.2;mso-line-height-rule:exactly;'
                        . "text-align:{$textAlign};";
                if ($textTransform !== 'none') {
                    $hStyle .= "text-transform:{$textTransform};";
                }
                $applyStyle($el, $hStyle);
            }
        }

        // ---------------------------------------------------------------
        // <ul> / <ol>
        // ---------------------------------------------------------------
        // O marcador vai INLINE (list-style-type): o CSS de cliente de e-mail —
        // e o reset do webmail — não pode ser presumido, e sem a propriedade a
        // lista chega sem bolinha/número. Espelha o .pdfb-tiptap do PDF.
        foreach ($xpath->query('.//ul | .//ol', $root) as $el) {
            /** @var \DOMElement $el */
            $marker = $el->nodeName === 'ol' ? 'decimal' : 'disc';
            $applyStyle($el, "margin:0 0 10px 0;padding-left:20px;list-style-position:outside;list-style-type:{$marker};");
        }

        // ---------------------------------------------------------------
        // <li> — full typography + tight bottom margin
        // ---------------------------------------------------------------
        foreach ($xpath->query('.//li', $root) as $el) {
            /** @var \DOMElement $el */
            $style = 'display:list-item;margin:0 0 4px 0;' . $baseTypography;
            $applyStyle($el, $style);
        }

        // ---------------------------------------------------------------
        // <blockquote> — left-border treatment (ColoredBlockquote may
        // already have set border-left-color via a data attribute, but we
        // still need margin, padding, and the default border declaration).
        // ---------------------------------------------------------------
        foreach ($xpath->query('.//blockquote', $root) as $el) {
            /** @var \DOMElement $el */
            // Determine border color: data attribute wins if present
            $borderColor = $el->getAttribute('data-border-color') ?: $fontColor;
            $bqStyle = 'margin:0 0 10px 0;'
                     . 'padding:8px 0 8px 12px;'
                     . "border-left:3px solid {$borderColor};"
                     . "font-family:{$fontFamily};"
                     . "font-size:{$fontSize}px;"
                     . "font-weight:{$fontWeight};"
                     . "color:{$fontColor};"
                     . "line-height:{$lineHeight};mso-line-height-rule:exactly;"
                     . "text-align:{$textAlign};";
            $applyStyle($el, $bqStyle);
        }

        // ---------------------------------------------------------------
        // <a> — Gmail strips color inheritance; must be explicit.
        //       Preserve existing color if TipTap already set one.
        // ---------------------------------------------------------------
        foreach ($xpath->query('.//a', $root) as $el) {
            /** @var \DOMElement $el */
            // Only add default color if no color is already present
            $existing = $el->getAttribute('style');
            if (stripos($existing, 'color') === false) {
                $applyStyle($el, "color:{$fontColor};text-decoration:underline;");
            } else {
                $applyStyle($el, 'text-decoration:underline;');
            }
        }

        // ---------------------------------------------------------------
        // Inline semantic marks that email clients may strip without explicit style
        // ---------------------------------------------------------------
        foreach ($xpath->query('.//strong | .//b', $root) as $el) {
            /** @var \DOMElement $el */
            $applyStyle($el, 'font-weight:700;');
        }
        foreach ($xpath->query('.//em | .//i', $root) as $el) {
            /** @var \DOMElement $el */
            $applyStyle($el, 'font-style:italic;');
        }
        foreach ($xpath->query('.//u', $root) as $el) {
            /** @var \DOMElement $el */
            $applyStyle($el, 'text-decoration:underline;');
        }
        foreach ($xpath->query('.//s | .//strike | .//del', $root) as $el) {
            /** @var \DOMElement $el */
            $applyStyle($el, 'text-decoration:line-through;');
        }

        // ---------------------------------------------------------------
        // Serialize back to HTML string.
        // DOMDocument adds the wrapper <div id="__pdfb_root__"> — extract
        // its innerHTML manually to return only the inner content.
        // ---------------------------------------------------------------
        return self::innerHtml($root, $doc);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private static function buildBaseTypography(
        string $fontFamily,
        int    $fontSize,
        string $fontWeight,
        string $fontColor,
        string $lineHeight,
        string $textAlign,
        string $textTransform
    ): string {
        $style = "font-family:{$fontFamily};"
               . "font-size:{$fontSize}px;"
               . "font-weight:{$fontWeight};"
               . "color:{$fontColor};"
               . "line-height:{$lineHeight};mso-line-height-rule:exactly;"
               . "text-align:{$textAlign};";

        if ($textTransform !== 'none') {
            $style .= "text-transform:{$textTransform};";
        }

        return $style;
    }

    /**
     * Returns the inner HTML of a DOMElement (equivalent to JS innerHTML).
     */
    private static function innerHtml(\DOMElement $node, \DOMDocument $doc): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
        return $html;
    }
}
