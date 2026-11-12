<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

/**
 * Extends the default tiptap-php Blockquote node with an optional
 * left-border colour — mirrors the React ColoredBlockquote extension
 * in packages/react/src/blocks/tiptap-extensions.ts.
 *
 * The React editor stores the colour as:
 *   { "type": "blockquote", "attrs": { "borderColor": "#ff0000" } }
 *
 * This class reads that attribute and emits an inline style so that
 * `border-left-color` is applied to the rendered <blockquote> element.
 * The CSS in document.blade.php provides the default 3px solid width/style
 * which the inline colour then overrides via CSS specificity.
 */
class ColoredBlockquote extends \Tiptap\Nodes\Blockquote
{
    public function addAttributes(): array
    {
        return [
            'borderColor' => [
                'default' => null,

                // Parse from HTML (for round-trip — not strictly needed for PDF)
                'parseHTML' => fn (\DOMElement $node) =>
                    $node->getAttribute('data-border-color') ?: null,

                // Emit inline style so the CSS default is overridden by specificity
                'renderHTML' => function (object $attributes): array {
                    $color = $attributes->borderColor ?? null;
                    if (!$color) {
                        return [];
                    }

                    return [
                        'data-border-color' => $color,
                        'style'             => "border-left-color:{$color}",
                    ];
                },
            ],
        ];
    }
}
