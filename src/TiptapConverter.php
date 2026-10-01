<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

use Tiptap\Editor;
use PdfBlock\Laravel\Email\EmailHtmlInliner;

/**
 * Converts TipTap ProseMirror JSON into HTML.
 *
 * Uses the official ueberdosis/tiptap-php package which supports the same
 * extensions as the React editor (StarterKit, TextAlign, Underline, Link, Image).
 *
 * Extension notes:
 * - TextAlign MUST list the node types it targets; omitting 'types' → no-op.
 * - ColoredBlockquote replaces StarterKit's built-in Blockquote so the
 *   custom `borderColor` attribute (from the React ColoredBlockquote extension)
 *   is serialised as an inline `border-left-color` style.
 * - Color / FontFamily default to types=['textStyle'] which is correct.
 */
class TiptapConverter
{
    private Editor $editor;

    public function __construct()
    {
        $this->editor = new Editor([
            'extensions' => [
                // Disable built-in Blockquote — we use ColoredBlockquote below.
                new \Tiptap\Extensions\StarterKit(['blockquote' => false]),

                // TextAlign MUST receive the node types it applies to.
                new \Tiptap\Extensions\TextAlign(['types' => ['heading', 'paragraph']]),

                new \Tiptap\Extensions\Color,
                new \Tiptap\Extensions\FontFamily,
                new \Tiptap\Marks\Underline,
                // Link com estilo configurável (cor + sublinhado) — espelha o
                // StyledLink do React; substitui o \Tiptap\Marks\Link padrão.
                new StyledLink,
                new \Tiptap\Nodes\Image,
                new \Tiptap\Marks\TextStyle,

                // Handles fontSize, lineHeight, letterSpacing on textStyle spans.
                new TextStyleAttributes,

                // Custom blockquote that carries the borderColor attribute.
                new ColoredBlockquote,

                // Cross-reference inline node (P8) → <a class="pdfb-xref" ...>.
                new Xref,

                // Variable chip (merge field). Normally resolved to text by the
                // BindingResolver BEFORE this converter runs; this handler is the
                // fallback so a raw 'variable' node never vanishes silently
                // (unknown nodes are dropped by tiptap-php) — it emits the label.
                new Variable,
            ],
        ]);
    }

    /**
     * Convert ProseMirror JSON to HTML string.
     *
     * Post-processing:
     * - Empty <p></p> → <p><br></p> so Chromium renders them with line height
     *   (mirrors TipTap's browser behaviour of inserting a <br> in empty blocks).
     */
    public function toHtml(array|string $content): string
    {
        if (is_string($content)) {
            $content = json_decode($content, true);
        }

        if (empty($content)) {
            return '';
        }

        $html = $this->editor->setContent($content)->getHTML();

        // Ensure empty paragraphs have height in headless Chromium.
        $html = str_replace('<p></p>', '<p><br></p>', $html);

        // Strip whitespace-only text nodes between block elements.
        // The .pdfb-tiptap container has white-space:pre-wrap (for reliable
        // space preservation), which would make inter-element newlines from
        // the HTML serialiser visible as blank lines in the PDF. Removing
        // them here keeps the output compact without touching content.
        $html = preg_replace(
            '~</(p|h[1-6]|ul|ol|li|blockquote|pre)>\s+<~',
            '</$1><',
            $html
        );

        return $html;
    }

    /**
     * Convert ProseMirror JSON to email-safe HTML with inline styles.
     *
     * Identical to toHtml() but additionally runs the output through
     * EmailHtmlInliner which injects typography styles directly onto every
     * block-level element (<p>, <h1>–<h6>, <li>, <blockquote>, etc.) and
     * fixes inline marks (<a>, <strong>, <em>, <u>, <s>).
     *
     * This is required because email clients — Outlook (Word engine), Gmail,
     * Yahoo Mail — do NOT reliably inherit CSS from a parent <div> or <td>.
     * Every element must carry its own inline `style` attribute.
     *
     * @param  array|string  $content    ProseMirror JSON
     * @param  array         $textProps  Typography context from the DSL block:
     *                                   fontFamily, fontSize, fontWeight,
     *                                   fontColor, lineHeight, textAlign,
     *                                   textTransform
     */
    public function toEmailHtml(array|string $content, array $textProps = []): string
    {
        $html = $this->toHtml($content);

        if (trim($html) === '') {
            return '';
        }

        return EmailHtmlInliner::inline($html, $textProps);
    }
}
