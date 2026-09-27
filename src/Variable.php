<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

use Tiptap\Core\Node;
use Tiptap\Utils\HTML;

/**
 * Nó TipTap `variable` (chip de variável / merge field) — espelha a extensão
 * React `blocks/tiptap-variable.tsx`. Serialização na DSL:
 *   `{ type: 'variable', attrs: { path, label } }`.
 *
 * No pipeline normal, o `BindingResolver` (PHP) resolve o nó ANTES do TipTap:
 * vira um nó de texto com o VALOR do registro. Este handler é a REDE DE SEGURANÇA
 * para os nós `variable` que chegam CRUS ao conversor (ex.: sem escopo/dados, ou
 * um caminho que não passou pelo expander) — sem ele, o tiptap-php descarta o nó
 * DESCONHECIDO silenciosamente e o chip SOME. Em vez disso, emitimos o rótulo
 * (ou o path) dentro de um `<span class="pdfb-var">`, para a variável nunca sumir.
 *
 * `renderText` fornece o conteúdo textual (o nó é atom, sem filhos); o content
 * hole `0` no `renderHTML` faz o DOMSerializer envolvê-lo no <span> — mesmo padrão
 * do nó `mention` do tiptap-php.
 */
class Variable extends Node
{
    public static $name = 'variable';

    public function addAttributes(): array
    {
        return [
            'path' => ['default' => ''],
            'label' => ['default' => ''],
        ];
    }

    public function parseHTML(): array
    {
        return [['tag' => 'span[data-pdfb-var]']];
    }

    public function renderText($node): string
    {
        $attrs = $node->attrs ?? new \stdClass();
        $label = is_object($attrs) ? (string) ($attrs->label ?? '') : '';
        $path = is_object($attrs) ? (string) ($attrs->path ?? '') : '';

        // Valor resolvido é emitido upstream (BindingResolver → nó de texto). Aqui,
        // sem valor, cai no rótulo — ou no path como último recurso — para não sumir.
        return $label !== '' ? $label : $path;
    }

    public function renderHTML($node, $HTMLAttributes = []): array
    {
        $attrs = $node->attrs ?? new \stdClass();
        $path = is_object($attrs) ? (string) ($attrs->path ?? '') : '';

        return [
            'span',
            HTML::mergeAttributes(['class' => 'pdfb-var', 'data-pdfb-var' => $path], $HTMLAttributes),
            0, // content hole → renderText() é injetado dentro do <span>
        ];
    }
}
