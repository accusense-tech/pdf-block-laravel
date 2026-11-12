<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Contracts;

/**
 * Contract for a custom block renderer registered via the Plugin API.
 *
 * Implementations produce the final HTML for a single block node. The
 * `$block` argument is the DSL payload as-is (associative array with
 * `id`, `type`, `meta`, `styles`, and any plugin-specific fields).
 *
 * The `$context` bag carries per-render data injected by the host — e.g.
 * `['mode' => 'email', 'data' => $clipping, 'tiptap' => $converter]`.
 */
interface BlockRenderer
{
    /**
     * Render the block as an HTML fragment. The caller is responsible for
     * wrapping the fragment in the outer DSL scaffolding (stripe/column).
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $context
     */
    public function render(array $block, array $context = []): string;
}
