# pdf-block/laravel

Pacote Laravel para renderizar documentos da DSL do **pdf-block** em PDF nativo. Texto selecionável, links clicáveis, imagens embutidas — PDF de verdade, não screenshot.

Suporta dois **drivers de renderização** intercambiáveis:

| Driver | Como funciona | Quando usar |
|---|---|---|
| `local` | Chromium CLI no mesmo container que o PHP, via `symfony/process` | Desenvolvimento e ambientes controlados |
| `browserless` | HTTP POST a um serviço [Browserless v2](https://docs.browserless.io/HTTP-APIs/pdf) (auto-hospedado ou gerenciado) via libcurl nativo | Produção, escalabilidade horizontal, separação de responsabilidades |

---

## Instalação

```bash
composer require pdf-block/laravel
```

O service provider é registrado automaticamente via auto-discovery do Laravel.

### Publicar config (opcional)

```bash
php artisan vendor:publish --tag=pdf-block-config
```

Cria `config/pdf-block.php` no projeto com todas as opções disponíveis.

### Publicar views (opcional)

```bash
php artisan vendor:publish --tag=pdf-block-views
```

Permite customizar os Blade templates em `resources/views/vendor/pdf-block/`.

---

## Uso

### Injeção de dependência

```php
use PdfBlock\Laravel\PdfBlockRenderer;

class ExportController extends Controller
{
    public function pdf(Request $request, PdfBlockRenderer $renderer)
    {
        $document = $request->input('document');

        return $renderer->toPdf($document)->toResponse('documento.pdf');
    }
}
```

### API pública

```php
$renderer = app(PdfBlockRenderer::class);

// DSL → HTML (útil para debug, e-mail, preview)
$html = $renderer->toHtml($document);

// DSL → PDF
$pdf = $renderer->toPdf($document);

// Download (Content-Disposition: attachment)
return $pdf->toResponse('fatura.pdf');

// Preview inline no browser (Content-Disposition: inline)
return $pdf->toInlineResponse('preview.pdf');

// Salvar em disco
$pdf->save(storage_path('exports/fatura.pdf'));

// Conteúdo binário raw
$binary = $pdf->content();

// Tamanho em bytes
$bytes = $pdf->size();
```

---

## Configuração

```php
// config/pdf-block.php

return [
    // Driver ativo: 'local' ou 'browserless'
    'driver' => env('PDF_BLOCK_DRIVER', 'local'),

    'drivers' => [
        'local' => [
            'chrome_path' => env('PDF_BLOCK_CHROME_PATH', '/usr/bin/chromium'),
            'timeout'     => (int) env('PDF_BLOCK_TIMEOUT', 30),
            'chrome_args' => [ /* defaults otimizados — ver config/pdf-block.php */ ],
        ],

        'browserless' => [
            'url'        => env('PDF_BLOCK_BROWSERLESS_URL', 'http://browserless:3000'),
            'token'      => env('PDF_BLOCK_BROWSERLESS_TOKEN'),
            'timeout'    => (int) env('PDF_BLOCK_BROWSERLESS_TIMEOUT', 30),
            'wait_until' => env('PDF_BLOCK_BROWSERLESS_WAIT_UNTIL', 'load'),

            // Bloqueio de recursos no nível do Puppeteer — cancela requests
            // ANTES de ir pra rede. Ganho de 200–800ms por PDF.
            'reject_resource_types'  => ['media', 'websocket', 'eventsource', 'manifest', 'texttrack', 'other'],
            'reject_request_pattern' => ['google-analytics\\.com', 'googletagmanager\\.com', /* ... */],
        ],
    ],

    'fonts' => [
        'local_fonts' => [ /* 20 famílias corporativas — ver config/pdf-block.php */ ],
    ],
];
```

### Variáveis de ambiente

**Driver local:**

```dotenv
PDF_BLOCK_DRIVER=local
PDF_BLOCK_CHROME_PATH=/usr/bin/chromium
PDF_BLOCK_TIMEOUT=30
```

**Driver browserless:**

```dotenv
PDF_BLOCK_DRIVER=browserless
PDF_BLOCK_BROWSERLESS_URL=http://browserless:3000
PDF_BLOCK_BROWSERLESS_TOKEN=
PDF_BLOCK_BROWSERLESS_TIMEOUT=30
PDF_BLOCK_BROWSERLESS_WAIT_UNTIL=load
```

---

## Driver local

O driver padrão. Invoca o binário do Chromium diretamente via `symfony/process` com a flag `--print-to-pdf`. Não requer Node.js, Puppeteer ou Browsershot.

### Pré-requisitos

Chromium instalado no servidor ou container:

```bash
# Debian/Ubuntu
sudo apt install chromium fonts-noto-color-emoji

# Alpine (Docker)
apk add chromium font-noto-emoji
```

> **fonts-noto-color-emoji**: necessário para renderizar emojis. Sem ela, emojis aparecem como quadrados vazios no PDF.

### Dockerfile (desenvolvimento)

```dockerfile
FROM php:8.2-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        chromium \
        fonts-noto-color-emoji \
        libzip-dev unzip git \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
```

### Produção com `www-data` (PHP-FPM)

O `chrome_crashpad_handler` usa `ptrace(PTRACE_ATTACH)` no processo pai do Chromium. Quando o PHP roda como `www-data` sem `CAP_SYS_PTRACE`, o kernel nega essa chamada e o Chromium recebe `SIGTRAP`.

Os flags `--disable-crash-reporter` e `--disable-breakpad` (já incluídos nos defaults) reduzem a frequência desse problema, mas não o eliminam em todos os builds do Chromium no Debian/Ubuntu.

**Solução definitiva via `setcap`** (requer `libcap2-bin`):

```dockerfile
RUN apt-get install -y libcap2-bin \
    && setcap cap_sys_ptrace+ep /usr/lib/chromium/chrome_crashpad_handler
```

**Alternativa recomendada para produção:** use o driver `browserless`, onde o Chromium roda isolado em um container próprio — o problema de permissão não existe nesse contexto.

---

## Driver browserless

Envia o HTML via `POST /pdf` para qualquer serviço compatível com a [API Browserless v2](https://docs.browserless.io/HTTP-APIs/pdf). Elimina o Chromium do container PHP por completo.

O driver é implementado sobre **libcurl nativo** (`ext-curl`) — sem Guzzle. Motivos:

- **Roteamento confiável.** libcurl emite sempre request-line em origin-form (`POST /pdf HTTP/1.1`), que é o único formato aceito pelo router do Browserless v2. Clientes de alto nível podem serializar em absolute-form para hosts sem TLD (ex.: `browserless` em rede Docker), resultando em HTTP 404 "No matching HTTP route handler".
- **Menor overhead.** Sem wrappers PSR-7 / streams / middlewares — ~5 ms a menos por request e pegada de memória menor (útil em containers com `mem_limit` baixo).
- **Menos superfície de erro.** Zero dependência transitiva.

O HTML gerado pelo Blade já carrega toda a lógica de dimensionamento: um script injeta `@page { size: Wmm Hmm; margin: 0 }` via `beforeprint`, medindo o `scrollHeight` real após fontes e imagens carregadas. O driver envia `preferCSSPageSize: true` para que o Puppeteer obedeça esse `@page` — tamanho, margens e orientação vêm direto da DSL via CSS, sem duplicação no JSON da requisição.

### Otimizações ativas por padrão

| Otimização | Efeito |
|---|---|
| `waitUntil: 'load'` | Fontes são 100% locais (fontconfig), imagens já são aguardadas pelo `load`. Usar `networkidle0` só é necessário se o template dispara XHR/fetch após o load. |
| `preferCSSPageSize: true` | Tamanho do papel lido do `@page` do HTML — evita duplicar config na request. |
| `rejectResourceTypes` | Cancela `media`, `websocket`, `eventsource`, `manifest`, `texttrack`, `other` antes da rede. |
| `rejectRequestPattern` | Bloqueia trackers conhecidos (Google Analytics, GTM, DoubleClick, Facebook Pixel, Hotjar, Mixpanel, Segment, FullStory, Clarity). |
| `CURLOPT_TCP_NODELAY` | Desliga Nagle — request chega ao Browserless sem o delay de 40 ms. |
| `Expect:` header removido | Evita handshake 100-continue que alguns proxies travam. |
| HTTP/1.1 forçado | Garante request-line em origin-form compatível com o router do Browserless. |

### Docker Compose (desenvolvimento)

```yaml
services:
  api:
    build: .
    env_file: .env
    depends_on:
      - browserless
    mem_limit: 64m

  browserless:
    image: ghcr.io/accusense-tech/pdf-block-browserless:latest
    ports:
      - "3001:3000"
    environment:
      TOKEN: ""
      TIMEOUT: 30000
      CONCURRENT: "1"
      PREBOOT_CHROME: "false"
      ENABLE_DEBUGGER: "false"
      ENABLE_API_GET: "false"
      DEFAULT_BLOCK_ADS: "false"
      DEFAULT_STEALTH: "false"
      DEBUG: ""
      NODE_OPTIONS: "--max-old-space-size=96"
    mem_limit: 336m
```

```dotenv
# .env
PDF_BLOCK_DRIVER=browserless
PDF_BLOCK_BROWSERLESS_URL=http://browserless:3000
```

> **Budget de memória**: com esse stack o pico observado é `api ~50 MB` + `browserless ~150 MB` por PDF de 1–5 páginas. Cabe confortavelmente em 400 MB totais.

> **`DEFAULT_BLOCK_ADS=false` é intencional.** O ad-blocker embutido do Browserless baixa listas de filtros em background e pode travar o `setContent`. O bloqueio de trackers é feito no nível do driver via `reject_request_pattern` — mais previsível e sem dependência de DNS externo.

### Serviço gerenciado (browserless.io)

```dotenv
PDF_BLOCK_DRIVER=browserless
PDF_BLOCK_BROWSERLESS_URL=https://production-sfo.browserless.io
PDF_BLOCK_BROWSERLESS_TOKEN=seu-token
PDF_BLOCK_BROWSERLESS_TIMEOUT=30
```

### Driver customizado

Implemente a interface `PdfBlock\Laravel\Contracts\PdfDriver` e registre o binding no seu service provider:

```php
use PdfBlock\Laravel\Contracts\PdfDriver;

// AppServiceProvider::register()
$this->app->bind(PdfDriver::class, MyCustomDriver::class);
```

---

## Fontes locais

O pacote opera com fontes **100% locais** — zero tráfego para `fonts.googleapis.com` em runtime. A imagem Docker do Browserless (`ghcr.io/accusense-tech/pdf-block-browserless:latest`) já inclui as 20 famílias curadas abaixo, instaladas no fontconfig do sistema:

**Sans-serif:** Inter, Roboto, Open Sans, Lato, Source Sans 3, Noto Sans, Work Sans, DM Sans, Montserrat
**Serif:** Spectral *(padrão)*, Merriweather, Lora, Source Serif 4, PT Serif, EB Garamond, Libre Baskerville, Playfair Display, Noto Serif
**Display:** Oswald
**Mono:** Roboto Mono

A lista vive em `config/pdf-block.php → fonts.local_fonts` e está sincronizada com o catálogo de fontes do pacote React (`packages/react/src/fontCatalog.ts`).

Para adicionar uma família: atualize `docker/install-fonts.sh` (baixa os arquivos no build), `config/pdf-block.php → local_fonts` e o `fontCatalog.ts` do React. O workflow `browserless-image.yml` detecta mudanças em `install-fonts.sh` e rebuilda automaticamente a imagem.

---

## Imagem Docker pronta

A imagem [`ghcr.io/accusense-tech/pdf-block-browserless`](https://github.com/accusense-tech/pdf-block-laravel/pkgs/container/pdf-block-browserless) é a maneira recomendada de rodar o driver `browserless`. Ela estende `ghcr.io/browserless/chromium:latest` com:

- **Noto Color Emoji** como família de emoji (mesmo visual do driver local).
- As **20 fontes corporativas** instaladas no fontconfig.
- Sem alteração de entrypoint — todas as variáveis de ambiente do Browserless v2 continuam funcionando.

**Tags disponíveis:**

| Tag | Uso |
|---|---|
| `latest` | Última build da branch `main` |
| `vX.Y.Z` | Vinculada ao release correspondente do pacote Composer |
| `vX.Y` | Major.minor (atualiza em novos patches) |

Veja [`docs/image-build.md`](docs/image-build.md) para detalhes do pipeline de build e como gerar uma nova versão da imagem.

---

## Diagnóstico

O sandbox inclui dois endpoints para validar a configuração de cada driver sem gerar um PDF completo:

### Verificar driver local

```bash
GET /api/diagnostics/local
```

Executa `chromium --version` e retorna:

```json
{
  "driver": "local",
  "status": "ok",
  "chrome_path": "/usr/bin/chromium",
  "version": "Chromium 124.0.6367.82 built on Debian 12.5, running on Debian 12.5"
}
```

### Verificar driver browserless

```bash
GET /api/diagnostics/browserless
```

Faz um request de health check ao serviço e retorna:

```json
{
  "driver": "browserless",
  "status": "ok",
  "url": "http://browserless:3000",
  "response_ms": 42,
  "service": { "Browser": "Chrome/124...", "Protocol": "1.3" }
}
```

Em caso de erro, ambos os endpoints retornam HTTP 500/502 com `"status": "error"` e uma `"message"` descritiva.

---

## Arquitetura

```
DSL JSON (do editor React)
    │
    ▼
PdfBlockRenderer::toHtml()
    │  Blade templates renderizam cada bloco
    │  com inline styles idênticos ao editor
    ▼
HTML string standalone
    │
    ├─── driver: local ──────────────────────┐
    │    LocalDriver::render()               │
    │    Chromium CLI --print-to-pdf         │
    │    (symfony/process)                   │
    │                                        │
    └─── driver: browserless ────────────────┤
         BrowserlessDriver::render()         │
         POST {url}/pdf (libcurl nativo)     │
         HTTP/1.1 origin-form · TCP_NODELAY  │
                                             ▼
                                      PdfResult (PDF nativo)
```

### Paridade visual

Os Blade templates replicam 1:1 os renderers React. Ambos usam 100% inline styles via as mesmas funções CSS helper (portadas de TypeScript para PHP em `StyleHelpers.php`).

---

## DSL — Formato do documento

O pacote espera receber a mesma DSL JSON produzida pelo editor `@pdf-block/react`:

```json
{
  "id": "uuid",
  "version": "2.0.0",
  "meta": { "title": "Meu documento", "locale": "pt-BR", "tags": [] },
  "pageSettings": {
    "paperSize": { "preset": "a4", "width": 210, "height": 297 },
    "orientation": "portrait",
    "margins": { "top": 20, "right": 20, "bottom": 20, "left": 20 },
    "defaultFontFamily": "Spectral, serif"
  },
  "globalStyles": {
    "pageBackground": "#ffffff",
    "contentBackground": "#ffffff",
    "defaultFontColor": "#333333"
  },
  "blocks": [...]
}
```

### Blocos suportados

| Bloco | Descrição |
|---|---|
| `text` | Rich text via TipTap JSON → HTML (`ueberdosis/tiptap-php`) |
| `image` | Imagem com alignment, objectFit, bordas |
| `button` | Link estilizado como botão (clicável no PDF) |
| `divider` | Linha horizontal |
| `spacer` | Espaço vertical |
| `banner` | Imagem de fundo com overlay e textos |
| `table` | Tabela com header, zebra, bordas |
| `qrcode` | QR code |
| `chart` | Gráfico de barras (CSS puro) |
| `pagebreak` | Quebra de página forçada |

---

## Customização de templates

Após publicar as views (`--tag=pdf-block-views`), edite os templates em `resources/views/vendor/pdf-block/`:

```
resources/views/vendor/pdf-block/
├── document.blade.php     # Layout principal
├── stripe.blade.php       # Stripe (banda horizontal)
├── structure.blade.php    # Structure (row de colunas)
├── block.blade.php        # Router de blocos
└── blocks/
    ├── text.blade.php
    ├── image.blade.php
    ├── button.blade.php
    ├── divider.blade.php
    ├── spacer.blade.php
    ├── banner.blade.php
    ├── table.blade.php
    ├── qrcode.blade.php
    ├── chart.blade.php
    └── pagebreak.blade.php
```

---

## Integração com o editor React

### Endpoint Laravel

```php
// routes/api.php
Route::post('/export/pdf', function (Request $request, PdfBlockRenderer $renderer) {
    $document = $request->validate([
        'document'              => 'required|array',
        'document.blocks'       => 'required|array',
        'document.pageSettings' => 'required|array',
        'document.globalStyles' => 'required|array',
    ])['document'];

    return $renderer->toPdf($document)->toResponse('documento.pdf');
});
```

### No React

```tsx
const editorRef = useRef<PDFBuilderRef>(null);

const exportPdf = async () => {
  const doc = editorRef.current?.getDocument();
  if (!doc) return;

  const res = await fetch('/api/export/pdf', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ document: doc }),
  });

  const blob = await res.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'documento.pdf';
  a.click();
};
```

---

## Requisitos

- PHP ≥ 8.2
- Extensões PHP: `ext-curl`, `ext-json`
- Laravel 11.x ou 12.x
- **Driver local:** Chromium instalado no servidor
- **Driver browserless:** Serviço Browserless v2 acessível via HTTP

## Licença

MIT
# pdf-block/laravel

Pacote Laravel para renderizar documentos da DSL do **pdf-block** em PDF nativo. Texto selecionável, links clicáveis, imagens embutidas — PDF de verdade, não screenshot.

Suporta dois **drivers de renderização** intercambiáveis:

| Driver | Como funciona | Quando usar |
|---|---|---|
| `local` | Chromium CLI no mesmo container que o PHP | Desenvolvimento e ambientes controlados |
| `browserless` | HTTP POST a um serviço Browserless v1 (auto-hospedado ou gerenciado) | Produção, servidores com `www-data` sem `CAP_SYS_PTRACE` |

---

## Instalação

```bash
composer require pdf-block/laravel
```

O service provider é registrado automaticamente via auto-discovery do Laravel.

### Publicar config (opcional)

```bash
php artisan vendor:publish --tag=pdf-block-config
```

Cria `config/pdf-block.php` no projeto com todas as opções disponíveis.

### Publicar views (opcional)

```bash
php artisan vendor:publish --tag=pdf-block-views
```

Permite customizar os Blade templates em `resources/views/vendor/pdf-block/`.

---

## Uso

### Injeção de dependência

```php
use PdfBlock\Laravel\PdfBlockRenderer;

class ExportController extends Controller
{
    public function pdf(Request $request, PdfBlockRenderer $renderer)
    {
        $document = $request->input('document');

        return $renderer->toPdf($document)->toResponse('documento.pdf');
    }
}
```

### API pública

```php
$renderer = app(PdfBlockRenderer::class);

// DSL → HTML (útil para debug, e-mail, preview)
$html = $renderer->toHtml($document);

// DSL → PDF
$pdf = $renderer->toPdf($document);

// Download (Content-Disposition: attachment)
return $pdf->toResponse('fatura.pdf');

// Preview inline no browser (Content-Disposition: inline)
return $pdf->toInlineResponse('preview.pdf');

// Salvar em disco
$pdf->save(storage_path('exports/fatura.pdf'));

// Conteúdo binário raw
$binary = $pdf->content();

// Tamanho em bytes
$bytes = $pdf->size();
```

---

## Configuração

```php
// config/pdf-block.php

return [
    // Driver ativo: 'local' ou 'browserless'
    'driver' => env('PDF_BLOCK_DRIVER', 'local'),

    'drivers' => [
        'local' => [
            'chrome_path' => env('PDF_BLOCK_CHROME_PATH', '/usr/bin/chromium'),
            'timeout'     => (int) env('PDF_BLOCK_TIMEOUT', 30),
            'chrome_args' => [
                '--headless',
                '--ozone-platform=headless',
                '--no-sandbox',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--run-all-compositor-stages-before-draw',
                '--disable-crash-reporter',
                '--disable-breakpad',
            ],
        ],

        'browserless' => [
            'url'     => env('PDF_BLOCK_BROWSERLESS_URL', 'http://browserless:3000'),
            'timeout' => (int) env('PDF_BLOCK_BROWSERLESS_TIMEOUT', 30),
        ],
    ],
];
```

### Variáveis de ambiente

**Driver local:**

```dotenv
PDF_BLOCK_DRIVER=local
PDF_BLOCK_CHROME_PATH=/usr/bin/chromium
PDF_BLOCK_TIMEOUT=30
```

**Driver browserless:**

```dotenv
PDF_BLOCK_DRIVER=browserless
PDF_BLOCK_BROWSERLESS_URL=http://browserless:3000
PDF_BLOCK_BROWSERLESS_TIMEOUT=30
```

---

## Driver local

O driver padrão. Invoca o binário do Chromium diretamente via `symfony/process` com a flag `--print-to-pdf`. Não requer Node.js, Puppeteer ou Browsershot.

### Pré-requisitos

Chromium instalado no servidor ou container:

```bash
# Debian/Ubuntu
sudo apt install chromium fonts-noto-color-emoji

# Alpine (Docker)
apk add chromium font-noto-emoji
```

> **fonts-noto-color-emoji**: necessário para renderizar emojis. Sem ela, emojis aparecem como quadrados vazios no PDF.

### Dockerfile (desenvolvimento)

```dockerfile
FROM php:8.2-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        chromium \
        fonts-noto-color-emoji \
        libzip-dev unzip git \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
```

### Produção com `www-data` (PHP-FPM)

O `chrome_crashpad_handler` usa `ptrace(PTRACE_ATTACH)` no processo pai do Chromium. Quando o PHP roda como `www-data` sem `CAP_SYS_PTRACE`, o kernel nega essa chamada e o Chromium recebe `SIGTRAP`.

Os flags `--disable-crash-reporter` e `--disable-breakpad` (já incluídos nos defaults) reduzem a frequência desse problema, mas não o eliminam em todos os builds do Chromium no Debian/Ubuntu.

**Solução definitiva via `setcap`** (requer `libcap2-bin`):

```dockerfile
RUN apt-get install -y libcap2-bin \
    && setcap cap_sys_ptrace+ep /usr/lib/chromium/chrome_crashpad_handler
```

**Alternativa recomendada para produção:** use o driver `browserless`, onde o Chromium roda como root em container isolado — o problema de permissão não existe nesse contexto.

---

## Driver browserless

Envia o HTML via `POST /pdf` para qualquer serviço compatível com a [API Browserless v1](https://docs.browserless.io/HTTP-APIs/pdf). Elimina o Chromium do container PHP por completo.

O HTML gerado pelo Blade já carrega toda a lógica de dimensionamento: um script injeta `@page { size: Wmm Hmm; margin: 0 }` via `beforeprint`, medindo o `scrollHeight` real após fontes e imagens carregadas. O driver envia `preferCSSPageSize: true` para que o Puppeteer obedeça esse `@page` — tamanho, margens e orientação vêm diretamente da DSL via CSS, sem duplicação no JSON da requisição.

### Docker Compose (desenvolvimento)

```yaml
# docker-compose.yml
services:
  api:
    build: .
    env_file: .env
    depends_on:
      - browserless

  browserless:
    image: ghcr.io/browserless/chromium:latest
    ports:
      - "3000:3000"
    environment:
      TOKEN: ""         # sem autenticação (desenvolvimento)
      TIMEOUT: 30000
      CONCURRENT: 5
```

```dotenv
# .env
PDF_BLOCK_DRIVER=browserless
PDF_BLOCK_BROWSERLESS_URL=http://browserless:3000
```

### Serviço gerenciado (browserless.io)

```dotenv
PDF_BLOCK_DRIVER=browserless
PDF_BLOCK_BROWSERLESS_URL=https://chrome.browserless.io
PDF_BLOCK_BROWSERLESS_TIMEOUT=30
```

### Driver customizado

Implemente a interface `PdfBlock\Laravel\Contracts\PdfDriver` e registre o binding no seu service provider:

```php
use PdfBlock\Laravel\Contracts\PdfDriver;

// AppServiceProvider::register()
$this->app->bind(PdfDriver::class, MyCustomDriver::class);
```

---

## Diagnóstico

O sandbox inclui dois endpoints para validar a configuração de cada driver sem gerar um PDF completo:

### Verificar driver local

```bash
GET /api/diagnostics/local
```

Executa `chromium --version` e retorna:

```json
{
  "driver": "local",
  "status": "ok",
  "chrome_path": "/usr/bin/chromium",
  "version": "Chromium 124.0.6367.82 built on Debian 12.5, running on Debian 12.5"
}
```

### Verificar driver browserless

```bash
GET /api/diagnostics/browserless
```

Faz um request de health check ao serviço e retorna:

```json
{
  "driver": "browserless",
  "status": "ok",
  "url": "http://browserless:3000",
  "response_ms": 42,
  "service": { "Browser": "Chrome/124...", "Protocol": "1.3" }
}
```

Em caso de erro, ambos os endpoints retornam HTTP 500/502 com `"status": "error"` e uma `"message"` descritiva.

---

## Arquitetura

```
DSL JSON (do editor React)
    │
    ▼
PdfBlockRenderer::toHtml()
    │  Blade templates renderizam cada bloco
    │  com inline styles idênticos ao editor
    ▼
HTML string standalone
    │
    ├─── driver: local ──────────────────────┐
    │    LocalDriver::render()               │
    │    Chromium CLI --print-to-pdf         │
    │    (symfony/process)                   │
    │                                        │
    └─── driver: browserless ────────────────┤
         BrowserlessDriver::render()         │
         POST {url}/pdf com opções Puppeteer │
         (illuminate/http)                   │
                                             ▼
                                      PdfResult (PDF nativo)
```

### Paridade visual

Os Blade templates replicam 1:1 os renderers React. Ambos usam 100% inline styles via as mesmas funções CSS helper (portadas de TypeScript para PHP em `StyleHelpers.php`).

---

## DSL — Formato do documento

O pacote espera receber a mesma DSL JSON produzida pelo editor `@pdf-block/react`:

```json
{
  "id": "uuid",
  "version": "2.0.0",
  "meta": { "title": "Meu documento", "locale": "pt-BR", "tags": [] },
  "pageSettings": {
    "paperSize": { "preset": "a4", "width": 210, "height": 297 },
    "orientation": "portrait",
    "margins": { "top": 20, "right": 20, "bottom": 20, "left": 20 },
    "defaultFontFamily": "Inter, sans-serif"
  },
  "globalStyles": {
    "pageBackground": "#ffffff",
    "contentBackground": "#ffffff",
    "defaultFontColor": "#333333"
  },
  "blocks": [...]
}
```

### Blocos suportados

| Bloco | Descrição |
|---|---|
| `text` | Rich text via TipTap JSON → HTML (`ueberdosis/tiptap-php`) |
| `image` | Imagem com alignment, objectFit, bordas |
| `button` | Link estilizado como botão (clicável no PDF) |
| `divider` | Linha horizontal |
| `spacer` | Espaço vertical |
| `banner` | Imagem de fundo com overlay e textos |
| `table` | Tabela com header, zebra, bordas |
| `qrcode` | QR code |
| `chart` | Gráfico de barras (CSS puro) |
| `pagebreak` | Quebra de página forçada |

---

## Customização de templates

Após publicar as views (`--tag=pdf-block-views`), edite os templates em `resources/views/vendor/pdf-block/`:

```
resources/views/vendor/pdf-block/
├── document.blade.php     # Layout principal
├── stripe.blade.php       # Stripe (banda horizontal)
├── structure.blade.php    # Structure (row de colunas)
├── block.blade.php        # Router de blocos
└── blocks/
    ├── text.blade.php
    ├── image.blade.php
    ├── button.blade.php
    ├── divider.blade.php
    ├── spacer.blade.php
    ├── banner.blade.php
    ├── table.blade.php
    ├── qrcode.blade.php
    ├── chart.blade.php
    └── pagebreak.blade.php
```

---

## Integração com o editor React

### Endpoint Laravel

```php
// routes/api.php
Route::post('/export/pdf', function (Request $request, PdfBlockRenderer $renderer) {
    $document = $request->validate([
        'document'              => 'required|array',
        'document.blocks'       => 'required|array',
        'document.pageSettings' => 'required|array',
        'document.globalStyles' => 'required|array',
    ])['document'];

    return $renderer->toPdf($document)->toResponse('documento.pdf');
});
```

### No React

```tsx
const editorRef = useRef<PDFBuilderRef>(null);

const exportPdf = async () => {
  const doc = editorRef.current?.getDocument();
  if (!doc) return;

  const res = await fetch('/api/export/pdf', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ document: doc }),
  });

  const blob = await res.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'documento.pdf';
  a.click();
};
```

---

## Migração da config anterior

Se você usava uma versão anterior do pacote, a estrutura de configuração foi alterada. Re-publique o arquivo de config e ajuste suas variáveis de ambiente:

**Antes (configuração plana):**

```php
return [
    'chrome_path' => env('PDF_BLOCK_CHROME_PATH', '/usr/bin/chromium'),
    'timeout'     => (int) env('PDF_BLOCK_TIMEOUT', 30),
    'chrome_args' => [...],
];
```

**Depois (configuração por driver):**

```php
return [
    'driver' => env('PDF_BLOCK_DRIVER', 'local'),
    'drivers' => [
        'local' => [
            'chrome_path' => env('PDF_BLOCK_CHROME_PATH', '/usr/bin/chromium'),
            'timeout'     => (int) env('PDF_BLOCK_TIMEOUT', 30),
            'chrome_args' => [...],
        ],
        'browserless' => [...],
    ],
];
```

As variáveis `PDF_BLOCK_CHROME_PATH` e `PDF_BLOCK_TIMEOUT` continuam funcionando — apenas foram movidas para dentro de `drivers.local`.

```bash
# Após atualizar o pacote:
php artisan vendor:publish --tag=pdf-block-config --force
```

---

## Requisitos

- PHP ≥ 8.2
- Laravel ≥ 11.0
- **Driver local:** Chromium instalado no servidor
- **Driver browserless:** Serviço Browserless v1 acessível via HTTP

## Licença

MIT
