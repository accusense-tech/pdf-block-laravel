<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

use PdfBlock\Laravel\Contracts\BlockRenderer;

/**
 * Adaptador que expõe um template Blade como um {@see BlockRenderer}.
 *
 * É o que permite os blocos BUILT-IN viverem no mesmo `BlockRendererRegistry`
 * dos plugins de app: em vez de dois `@switch` gigantes em `block.blade.php` e
 * `email/block.blade.php`, o ServiceProvider pré-registra um destes por
 * (modo, tipo) a partir de `config('pdf-block.blocks')`. O dispatcher Blade
 * passa a ser só: `BlockDefaults::fill` → wrapper meta/quebras → registry.
 *
 * O bloco é injetado na view sob a chave `$as` (default `block`); a `structure`
 * aninhada usa `structure` porque o template `pdf-block::structure` lê `$structure`
 * e faz o render recursivo. Todo o `$context` (tiptap, mode, data, fontStack,
 * fontColor) é repassado como variáveis da view — o `@include` herdava esse escopo
 * automaticamente, o `view()` não, então repassamos explicitamente.
 */
final class BladeBlockRenderer implements BlockRenderer
{
    /**
     * @param  string  $view  Nome da view Blade (ex.: `pdf-block::blocks.text`).
     * @param  string  $as    Nome da variável sob a qual o bloco entra na view.
     */
    public function __construct(
        private readonly string $view,
        private readonly string $as = 'block',
    ) {
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $context
     */
    public function render(array $block, array $context = []): string
    {
        return view($this->view, array_merge($context, [$this->as => $block]))->render();
    }
}
