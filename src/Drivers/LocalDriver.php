<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Drivers;

use PdfBlock\Laravel\Contracts\PdfDriver;
use Symfony\Component\Process\Process;

/**
 * Driver local: gera PDF invocando o binário do Chromium via CLI.
 *
 * É o driver padrão. Requer Chromium instalado no servidor ou container.
 * Para rodar como usuário não-root, use --no-sandbox (já incluso nos defaultArgs)
 * combinado com --disable-crash-reporter e --disable-breakpad para evitar que
 * o crashpad handler seja spawned (falha com EPERM quando no_new_privs está ativo).
 */
class LocalDriver implements PdfDriver
{
    private string $chromeBin;
    private int $timeout;
    private array $defaultArgs;

    public function __construct(private readonly array $config)
    {
        $this->chromeBin = $config['chrome_path'] ?? '/usr/bin/chromium';
        $this->timeout   = (int) ($config['timeout'] ?? 30);

        // NOTE: --user-data-dir é intencionalmente omitido do default — ele é
        // adicionado em render() com um path único por requisição para evitar
        // a contenção de SingletonLock em /tmp/chrome-data entre requests
        // concorrentes (maior causa de lentidão/travamento em prod).
        //
        // Flags removidas (eram problemáticas/lentas):
        //   --run-all-compositor-stages-before-draw  (dobrava o tempo de paint)
        $this->defaultArgs = [
            '--headless=new',
            '--ozone-platform=headless',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-crash-reporter',
            '--disable-breakpad',
            '--hide-scrollbars',
            '--mute-audio',
            '--disable-extensions',
            '--disable-background-networking',
            '--disable-sync',
            '--disable-default-apps',
            '--no-first-run',
        ];
    }

    public function render(string $html, array $document): string
    {
        // Calcula viewport width = largura do papel em px (espelha mmToPx do React).
        // v3-aware: a config vive em sections[0].pageSetup (ver effectivePageSettings) —
        // ler `pageSettings` cru media o layout em A4 num payload v3.
        $ps        = \PdfBlock\Laravel\DocumentMigrator::effectivePageSettings($document);
        $paper     = is_array($ps['paperSize'] ?? null) ? $ps['paperSize'] : ['width' => 210, 'height' => 297];
        $landscape = ($ps['orientation'] ?? 'portrait') === 'landscape';
        $paperW    = $landscape ? ($paper['height'] ?? 297) : ($paper['width'] ?? 210);
        if (! is_numeric($paperW) || (float) $paperW <= 0) {
            $paperW = 210;
        }
        $viewportW = (int) round((float) $paperW * 96 / 25.4);

        // Dir único por requisição — evita SingletonLock entre processos concorrentes.
        $userDataDir = sys_get_temp_dir() . '/pdfblock_chrome_' . uniqid('', true);
        $tmpHtml = tempnam(sys_get_temp_dir(), 'pdfblock_') . '.html';
        $tmpPdf  = sys_get_temp_dir() . '/pdfblock_' . uniqid() . '.pdf';

        try {
            file_put_contents($tmpHtml, $html);

            $configArgs = $this->config['chrome_args'] ?? $this->defaultArgs;

            $args = array_merge(
                [$this->chromeBin],
                $configArgs,
                [
                    '--user-data-dir=' . $userDataDir,
                    '--window-size=' . $viewportW . ',1080',
                    '--no-pdf-header-footer',
                    // Virtual time budget permite que o Chrome aguarde fontes/imagens
                    // de forma determinística (em tempo virtual, não wall-clock) antes
                    // de imprimir. Sem isso, --print-to-pdf pode imprimir cedo demais
                    // ou ficar preso até o timeout.
                    '--virtual-time-budget=5000',
                    '--print-to-pdf=' . $tmpPdf,
                    'file://' . $tmpHtml,
                ]
            );

            $process = new Process($args);
            $process->setTimeout($this->timeout);
            $process->mustRun();

            return file_get_contents($tmpPdf);
        } finally {
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            // Remove user-data-dir único recursivamente.
            if (is_dir($userDataDir)) {
                $this->rmdirRecursive($userDataDir);
            }
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) && ! is_link($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
