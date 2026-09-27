<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

use InvalidArgumentException;
use PdfBlock\Laravel\Contracts\BlockRenderer;

/**
 * Per-mode registry of custom block renderers.
 *
 * Registrations are keyed by `(mode, type)` — the same block type can have
 * different renderers for `pdf` and `email`. Typically wired up from the
 * application's `AppServiceProvider::boot()`:
 *
 *   $this->app->make(BlockRendererRegistry::class)
 *       ->register('email', 'clipping.articleCard', ClippingArticleCardRenderer::class);
 */
class BlockRendererRegistry
{
    /** @var array<string, array<string, string|BlockRenderer>> */
    private array $renderers = [];

    /**
     * Register a renderer for a given (mode, type) pair.
     *
     * @param  'pdf'|'email'  $mode
     * @param  string|BlockRenderer  $renderer  A class-string (resolved via the container) or an instance.
     */
    public function register(string $mode, string $type, string|BlockRenderer $renderer): void
    {
        $this->renderers[$mode][$type] = $renderer;
    }

    /** Is there a renderer registered for this (mode, type)? */
    public function has(string $mode, string $type): bool
    {
        return isset($this->renderers[$mode][$type]);
    }

    /**
     * Resolve the renderer for a block. Returns `null` when no custom
     * renderer is registered — the caller should fall back to the
     * built-in Blade dispatcher.
     */
    public function for(string $mode, string $type): ?BlockRenderer
    {
        $entry = $this->renderers[$mode][$type] ?? null;
        if ($entry === null) {
            return null;
        }
        if (is_string($entry)) {
            $resolved = app($entry);
            if (! $resolved instanceof BlockRenderer) {
                throw new InvalidArgumentException(
                    "Renderer {$entry} does not implement " . BlockRenderer::class
                );
            }
            // Memoize so subsequent calls within the same request skip the container.
            $this->renderers[$mode][$type] = $resolved;
            return $resolved;
        }
        return $entry;
    }

    /**
     * Render directly — convenience that delegates to the resolved renderer.
     * Returns `null` when there is no match (same semantics as `for()`).
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $context
     */
    public function render(string $mode, array $block, array $context = []): ?string
    {
        $renderer = $this->for($mode, (string) ($block['type'] ?? ''));
        return $renderer?->render($block, $context);
    }
}
