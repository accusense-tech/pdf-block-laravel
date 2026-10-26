<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

use Tiptap\Core\Node;
use Tiptap\Utils\HTML;

/**
 * Nó TipTap `xref` (referência cruzada) — espelha a extensão React
 * `blocks/tiptap-xref.tsx`. Renderiza:
 *   <a class="pdfb-xref" data-mode="..." href="#<anchorId>">rótulo</a>
 *
 * O rótulo é o CONTEÚDO do nó (content hole `0`). O número da página vem do CSS
 * (`document.blade`: `target-counter`) — não é texto. Headings já têm âncora
 * (`pdfb-h-<blockId>-<i>`) injetada por `text.blade` para o TOC.
 */
class Xref extends Node
{
    public static $name = 'xref';

    public function addAttributes(): array
    {
        return [
            'anchorId' => ['default' => ''],
            'mode' => ['default' => 'label-page'],
        ];
    }

    public function parseHTML(): array
    {
        return [['tag' => 'a.pdfb-xref']];
    }

    public function renderHTML($node, $HTMLAttributes = []): array
    {
        $attrs = $node->attrs ?? new \stdClass();
        $anchor = is_object($attrs) ? (string) ($attrs->anchorId ?? '') : '';
        $mode = is_object($attrs) ? (string) ($attrs->mode ?? 'label-page') : 'label-page';

        return [
            'a',
            HTML::mergeAttributes(
                ['class' => 'pdfb-xref', 'data-mode' => $mode, 'href' => '#' . $anchor],
                $HTMLAttributes
            ),
            0, // content hole → o rótulo (texto filho) é renderizado dentro do <a>
        ];
    }
}
