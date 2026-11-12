<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Drivers;

use PdfBlock\Laravel\Contracts\PdfDriver;

/**
 * Driver browserless: gera PDF via POST ao endpoint /pdf de qualquer serviço
 * compatível com a API Browserless v2 (auto-hospedado ou browserless.io).
 *
 * Elimina o Chromium do container PHP — o browser roda em um container
 * isolado, onde pode ser executado como root sem problemas de permissão.
 *
 * Implementado com libcurl nativo (ext-curl) em vez de Guzzle. Motivo:
 * versões recentes do Guzzle (7.x) serializam a request em absolute-form
 * (`POST http://host:port/path HTTP/1.1`) quando o host não tem TLD
 * (ex. "browserless" em rede Docker), e o router do Browserless v2 rejeita
 * absolute-form com 404. libcurl direto sempre manda origin-form
 * (`POST /path HTTP/1.1`).
 *
 * Referências:
 *   https://docs.browserless.io/HTTP-APIs/pdf
 *   https://github.com/browserless/browserless/blob/main/src/shared/pdf.http.ts
 *   (BodySchema vs QuerySchema — `launch` e `token` são QUERY, não body.)
 */
class BrowserlessDriver implements PdfDriver
{
    private string $url;
    private int $timeout;
    private ?string $token;
    private string $waitUntil;
    private array $launchArgs;
    private array $rejectResourceTypes;
    private array $rejectRequestPattern;

    public function __construct(array $config)
    {
        $this->url       = rtrim($config['url'] ?? 'http://browserless:3000', '/');
        $this->timeout   = (int) ($config['timeout'] ?? 30);
        $this->token     = $config['token'] ?? null;

        // 'load' é seguro e rápido: fontes são 100% locais (fontconfig) e
        // imagens são aguardadas pelo próprio evento load. Use 'networkidle0'
        // apenas se o template fizer requests XHR/fetch após o load inicial.
        $this->waitUntil = $config['wait_until'] ?? 'load';

        // Flags extras repassadas via `?launch={"args":[...]}`. Default vazio
        // — configure via env DEFAULT_LAUNCH_ARGS do container browserless
        // (aplicado uma única vez no boot, não por request).
        $this->launchArgs = $config['launch_args'] ?? [];

        // ⚠️ NUNCA inclua 'image' — imagens S3/CDN são essenciais pro PDF.
        $this->rejectResourceTypes = $config['reject_resource_types'] ?? [
            'media',
            'websocket',
            'eventsource',
            'manifest',
            'texttrack',
            'other',
        ];

        // Regex (sintaxe JS) para bloquear URLs. Default bloqueia apenas
        // trackers/analytics — seguro para imagens S3 e CDNs legítimos.
        $this->rejectRequestPattern = $config['reject_request_pattern'] ?? [
            'google-analytics\\.com',
            'googletagmanager\\.com',
            'doubleclick\\.net',
            'facebook\\.(net|com)/tr',
            'hotjar\\.com',
            'mixpanel\\.com',
            'segment\\.(io|com)',
            'fullstory\\.com',
            'clarity\\.ms',
        ];
    }

    public function render(string $html, array $document): string
    {
        // ─── Query params (launch + token são QUERY no v2, não body) ─────
        $query = [];
        if ($this->token) {
            $query['token'] = $this->token;
        }
        if (! empty($this->launchArgs)) {
            $query['launch'] = json_encode(
                ['args' => array_values($this->launchArgs)],
                JSON_UNESCAPED_SLASHES,
            );
        }
        $endpoint = $this->url . '/pdf'
            . ($query ? '?' . http_build_query($query) : '');

        // ─── Body ────────────────────────────────────────────────────────
        $payload = [
            'html' => $html,

            // preferCSSPageSize: lê @page do HTML (size/margin) injetado pelo
            // JS do Blade antes do print, em vez de usar format/margin daqui.
            'options' => [
                'preferCSSPageSize' => true,
                'printBackground'   => true,
            ],

            'gotoOptions' => [
                'waitUntil' => $this->waitUntil,
                'timeout'   => $this->timeout * 1000,
            ],

            // Viewport = largura do papel em px. Sem isso o Chromium usa
            // 800 px default e quebra layout. Altura baixa (800) reduz área
            // de layout inicial; o JS do Blade mede o scrollHeight real.
            'viewport' => [
                'width'  => $this->calcViewportWidth($document),
                'height' => 800,
            ],
        ];

        if (! empty($this->rejectResourceTypes)) {
            $payload['rejectResourceTypes'] = $this->rejectResourceTypes;
        }
        if (! empty($this->rejectRequestPattern)) {
            $payload['rejectRequestPattern'] = $this->rejectRequestPattern;
        }

        return $this->postJson($endpoint, $payload);
    }

    /**
     * POST JSON via libcurl nativo — retorna o body binário (PDF).
     */
    private function postJson(string $url, array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException(
                'Falha ao serializar payload JSON: ' . json_last_error_msg(),
            );
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Falha ao inicializar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FAILONERROR    => false,
            // origin-form HTTP/1.1 — único formato que o router do Browserless
            // v2 reconhece com certeza. Guzzle manda absolute-form em alguns
            // cenários (hosts sem TLD em rede Docker) e quebra o route match.
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/pdf',
                // Remove Expect: 100-continue (alguns proxies travam nele).
                'Expect:',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            // +5s de margem sobre gotoOptions.timeout: se o Browserless
            // cancelar o goto primeiro, retornamos o erro estruturado dele
            // em vez de um timeout genérico de rede.
            CURLOPT_TIMEOUT        => $this->timeout + 5,
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err      = curl_error($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($response === false || $errno !== 0) {
            throw new \RuntimeException(sprintf(
                'Falha na chamada ao Browserless (cURL %d): %s',
                $errno,
                $err !== '' ? $err : 'erro desconhecido',
            ));
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'Browserless retornou HTTP %d: %s',
                $status,
                substr((string) $response, 0, 500),
            ));
        }

        return (string) $response;
    }

    /**
     * Largura do papel (mm) → pixels a 96 DPI. Espelha mmToPx do React.
     * v3-aware: a config de página do v3 vive em sections[0].pageSetup —
     * ler `pageSettings` cru media o layout em A4 e gerava cauda de fundo
     * gigante no PDF contínuo (ou corte com papel mais estreito que A4).
     */
    private function calcViewportWidth(array $document): int
    {
        $ps        = \PdfBlock\Laravel\DocumentMigrator::effectivePageSettings($document);
        $paper     = is_array($ps['paperSize'] ?? null) ? $ps['paperSize'] : [];
        $landscape = ($ps['orientation'] ?? 'portrait') === 'landscape';
        $paperW    = $landscape ? ($paper['height'] ?? 297) : ($paper['width'] ?? 210);
        if (! is_numeric($paperW) || (float) $paperW <= 0) {
            $paperW = 210;
        }

        return (int) round((float) $paperW * 96 / 25.4);
    }
}
