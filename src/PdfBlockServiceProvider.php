<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

use Illuminate\Support\ServiceProvider;
use PdfBlock\Laravel\Contracts\PdfDriver;
use PdfBlock\Laravel\Drivers\BrowserlessDriver;
use PdfBlock\Laravel\Drivers\LocalDriver;
use PdfBlock\Laravel\Email\EmailRenderer;

class PdfBlockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/pdf-block.php', 'pdf-block');

        // TiptapConverter é caro de instanciar (monta schema ProseMirror com
        // ~10 extensões). Registrar como singleton para reuso entre requests
        // no mesmo worker PHP-FPM (economiza ~10-30ms por PDF).
        $this->app->singleton(TiptapConverter::class);

        // Resolve o driver correto com base em config('pdf-block.driver')
        $this->app->singleton(PdfDriver::class, function ($app) {
            $config = $app['config']->get('pdf-block', []);
            $driver = $config['driver'] ?? 'local';

            return match ($driver) {
                'browserless' => new BrowserlessDriver(
                    config: $config['drivers']['browserless'] ?? [],
                ),
                default => new LocalDriver(
                    config: $config['drivers']['local'] ?? [],
                ),
            };
        });

        $this->app->singleton(PdfBlockRenderer::class, function ($app) {
            return new PdfBlockRenderer(
                driver: $app->make(PdfDriver::class),
                tiptap: $app->make(TiptapConverter::class),
                config: $app['config']->get('pdf-block', []),
            );
        });

        $this->app->alias(PdfBlockRenderer::class, 'pdf-block');

        // ── Plugin API + Email renderer ─────────────────────────────
        // Registry is a singleton so runtime registrations made from the host
        // application's service provider survive for the entire request cycle.
        $this->app->singleton(BlockRendererRegistry::class);

        $this->app->singleton(EmailRenderer::class, function ($app) {
            return new EmailRenderer(
                tiptap: $app->make(TiptapConverter::class),
                registry: $app->make(BlockRendererRegistry::class),
            );
        });

        $this->app->alias(EmailRenderer::class, 'pdf-block.email');
    }

    /**
     * Convenience: register a custom block renderer scoped to a given mode.
     * Mirror of the JS `registerBlockPlugin` — callable from any service
     * provider's `boot()` phase.
     *
     * Usage:
     *   $this->app->make(PdfBlockServiceProvider::class)
     *       ->extend('email', 'clipping.articleCard', ClippingArticleCardRenderer::class);
     *
     * @param  'pdf'|'email'  $mode
     * @param  class-string|\PdfBlock\Laravel\Contracts\BlockRenderer  $renderer
     */
    public function extend(string $mode, string $type, string|Contracts\BlockRenderer $renderer): self
    {
        $this->app->make(BlockRendererRegistry::class)->register($mode, $type, $renderer);
        return $this;
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'pdf-block');

        // Alias do middleware que blinda o JSON do TipTap contra o TrimStrings/
        // ConvertEmptyStringsToNull globais do Laravel (que comem os espaços do
        // texto e chegam a derrubar o bloco). Uso na rota que recebe documento:
        //   ->middleware('pdf-block.raw:document,block')
        // Ver Http\Middleware\PreserveDocumentWhitespace e docs/CONSUMIDORES.md.
        if ($this->app->bound('router')) {
            $this->app['router']->aliasMiddleware('pdf-block.raw', Http\Middleware\PreserveDocumentWhitespace::class);
        }

        // Renderer embutido do bloco repetidor (`core.repeater`) — disponível em ambos os modos.
        $registry = $this->app->make(BlockRendererRegistry::class);
        $registry->register('pdf', 'core.repeater', Blocks\RepeaterRenderer::class);
        $registry->register('email', 'core.repeater', Blocks\RepeaterRenderer::class);

        // Blocos BUILT-IN: pré-registra um BladeBlockRenderer por (modo, tipo) a
        // partir do mapa de config — é o que substituiu os dois @switch dos
        // dispatchers Blade. A guarda `! has()` PRESERVA a precedência do plugin:
        // se o app já registrou (ou registrar depois, via register() que
        // sobrescreve) um renderer para o mesmo (modo, tipo), ele vence o built-in.
        foreach ((array) config('pdf-block.blocks', []) as $mode => $map) {
            foreach ((array) $map as $type => $entry) {
                if ($registry->has($mode, (string) $type)) {
                    continue;
                }
                [$view, $as] = is_array($entry)
                    ? [$entry['view'], $entry['as'] ?? 'block']
                    : [$entry, 'block'];
                $registry->register($mode, (string) $type, new BladeBlockRenderer($view, $as));
            }
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/pdf-block.php' => config_path('pdf-block.php'),
            ], 'pdf-block-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/pdf-block'),
            ], 'pdf-block-views');
        }
    }
}
