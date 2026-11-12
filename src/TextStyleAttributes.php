<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

use Tiptap\Core\Extension;
use Tiptap\Utils\InlineStyle;

/**
 * Adds fontSize, lineHeight, and letterSpacing as global attributes on the
 * textStyle mark — mirroring the @tiptap/extension-text-style JS extensions
 * (FontSize, LineHeight) and any custom letterSpacing attribute.
 *
 * The JS editor stores these in ProseMirror JSON as:
 *   { "type": "textStyle", "attrs": { "fontSize": "19px", "lineHeight": "2.5", "letterSpacing": "1px" } }
 *
 * This extension maps them to inline styles on the rendered <span>.
 */
class TextStyleAttributes extends Extension
{
    public static $name = 'textStyleAttributes';

    public function addGlobalAttributes(): array
    {
        return [
            [
                'types' => ['textStyle'],
                'attributes' => [
                    'fontSize' => [
                        'default' => null,
                        'parseHTML' => fn(\DOMElement $node) =>
                            InlineStyle::getAttribute($node, 'font-size') ?? null,
                        'renderHTML' => function (object $attributes): ?array {
                            $value = $attributes->fontSize ?? null;
                            if (!$value) return null;
                            return ['style' => "font-size: {$value}"];
                        },
                    ],

                    'lineHeight' => [
                        'default' => null,
                        'parseHTML' => fn(\DOMElement $node) =>
                            InlineStyle::getAttribute($node, 'line-height') ?? null,
                        'renderHTML' => function (object $attributes): ?array {
                            $value = $attributes->lineHeight ?? null;
                            if (!$value) return null;
                            return ['style' => "line-height: {$value}"];
                        },
                    ],

                    'letterSpacing' => [
                        'default' => null,
                        'parseHTML' => fn(\DOMElement $node) =>
                            InlineStyle::getAttribute($node, 'letter-spacing') ?? null,
                        'renderHTML' => function (object $attributes): ?array {
                            $value = $attributes->letterSpacing ?? null;
                            if (!$value) return null;
                            return ['style' => "letter-spacing: {$value}"];
                        },
                    ],
                ],
            ],
        ];
    }
}
