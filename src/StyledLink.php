<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

use Tiptap\Marks\Link;
use Tiptap\Utils\HTML;

/**
 * Mark `link` com ESTILO configurável (cor + sublinhado) — espelha o StyledLink
 * do React (`blocks/tiptap-extensions.ts`). Padrão: herda a cor do texto (cor
 * padrão da página) e sublinha; o `style` inline sobrepõe quando há cor/sem
 * sublinhado.
 *
 * NÃO força `target="_blank"`/`rel` (o editor client também não emite), para
 * paridade visual e para não quebrar âncoras internas (`#id`) no PDF. A cor de
 * tema (`themeColor`) já foi resolvida em `color` pelo InlineThemeColorResolver
 * antes deste render.
 */
class StyledLink extends Link
{
    public function addOptions(): array
    {
        // Preserva allowedProtocols/isAllowedUri do pai; troca o HTMLAttributes
        // (sem target=_blank/rel) e garante a classe do CSS.
        return array_merge(parent::addOptions(), [
            'HTMLAttributes' => ['class' => 'pdfb-link'],
        ]);
    }

    public function addAttributes(): array
    {
        return array_merge(parent::addAttributes(), [
            'color' => [
                'default' => null,
                'renderHTML' => fn ($attrs) => isset($attrs->color) && $attrs->color
                    ? ['data-color' => $attrs->color] : [],
            ],
            'themeColor' => [
                'default' => null,
                'renderHTML' => fn ($attrs) => isset($attrs->themeColor) && $attrs->themeColor
                    ? ['data-theme-color' => $attrs->themeColor] : [],
            ],
            'underline' => [
                'default' => true,
                'renderHTML' => fn ($attrs) => [
                    'data-underline' => (isset($attrs->underline) && $attrs->underline === false) ? 'false' : 'true',
                ],
            ],
        ]);
    }

    public function renderHTML($mark, $HTMLAttributes = []): array
    {
        if (! $this->options['isAllowedUri']($HTMLAttributes['href'] ?? '')) {
            $HTMLAttributes['href'] = '';
        }

        $attrs = $mark->attrs ?? new \stdClass();
        $color = is_object($attrs) ? ($attrs->color ?? null) : null;
        $underline = is_object($attrs) ? ($attrs->underline ?? true) : true;

        $style = 'text-decoration:' . ($underline === false ? 'none' : 'underline');
        if (is_string($color) && $color !== '') {
            $style .= ';color:' . $color;
        }

        return [
            'a',
            HTML::mergeAttributes($this->options['HTMLAttributes'], $HTMLAttributes, ['style' => $style]),
            0,
        ];
    }
}
