# Instalação — pdf-block/laravel

Guia completo de instalação e configuração do pacote Laravel para renderização
de PDFs a partir da DSL do `@pdf-block/react`.

## Requisitos

| Requisito | Versão |
|---|---|
| PHP | ≥ 8.2 |
| Extensões PHP | `ext-curl`, `ext-json` |
| Laravel | 11.x ou 12.x |

Requisitos por driver:

| Driver | Requisito adicional |
|---|---|
| `local` | Chromium instalado no servidor/container (invocado por CLI via `symfony/process`) |
| `browserless` | Serviço [Browserless v2](https://docs.browserless.io/) acessível por HTTP |

> O pacote **não** requer Node.js, Puppeteer, Browsershot ou bibliotecas Chrome
> de userland. O driver `local` conversa com o Chromium via flag `--print-to-pdf`;
> o driver `browserless` usa libcurl nativo.

---

## Instalação

```bash
composer require pdf-block/laravel
```

O service provider é registrado automaticamente via
[Laravel Package Discovery](https://laravel.com/docs/packages#package-discovery).

---

## Dependências Composer

| Pacote | Descrição |
|---|---|
| `illuminate/support` ^11\|^12 | Helpers do Laravel |
| `illuminate/view` ^11\|^12 | Engine Blade |
| `symfony/process` ^6\|^7 | Invocação do Chromium (driver `local`) |
| `ueberdosis/tiptap-php` ^1\|^2 | Conversão TipTap JSON → HTML (blocos de texto) |

---

## Escolhendo um driver

### Driver `local` (padrão)

Recomendado para desenvolvimento e ambientes onde já existe controle total do
container (ex.: root no entrypoint).

**Instalar Chromium:**

```bash
# Debian/Ubuntu
sudo apt install -y chromium fonts-noto-color-emoji

# Alpine
apk add --no-cache chromium font-noto-emoji

# Amazon Linux / Fedora
sudo yum install -y chromium

# macOS (dev)
brew install --cask chromium
```

**Confirmar binário:**

```bash
which chromium || which chromium-browser || which google-chrome
# Esperado: /usr/bin/chromium  (ou similar)
```

### Driver `browserless`

Recomendado para produção. O Chromium roda em um container próprio
(`ghcr.io/accusense-tech/pdf-block-browserless:latest`) com as 20 famílias
corporativas pré-instaladas, e o container PHP fica enxuto (não precisa de
Chromium, fontes ou flags de `CAP_SYS_PTRACE`).

```yaml
# docker-compose.yml
services:
  api:
    build: .
    env_file: .env
    depends_on: [browserless]
    mem_limit: 64m

  browserless:
    image: ghcr.io/accusense-tech/pdf-block-browserless:latest
    ports: ["3001:3000"]
    environment:
      TOKEN: ""
      CONCURRENT: "1"
      PREBOOT_CHROME: "false"
      DEFAULT_BLOCK_ADS: "false"
      NODE_OPTIONS: "--max-old-space-size=96"
    mem_limit: 336m
```

```dotenv
PDF_BLOCK_DRIVER=browserless
PDF_BLOCK_BROWSERLESS_URL=http://browserless:3000
```

---

## Publicar config (opcional)

```bash
php artisan vendor:publish --tag=pdf-block-config
```

Cria `config/pdf-block.php`. Principais blocos:

```php
return [
    'driver' => env('PDF_BLOCK_DRIVER', 'local'),

    'drivers' => [
        'local' => [
            'chrome_path' => env('PDF_BLOCK_CHROME_PATH', '/usr/bin/chromium'),
            'timeout'     => (int) env('PDF_BLOCK_TIMEOUT', 30),
            'chrome_args' => [ /* flags de baixa memória, já otimizadas */ ],
        ],
        'browserless' => [
            'url'        => env('PDF_BLOCK_BROWSERLESS_URL', 'http://browserless:3000'),
            'token'      => env('PDF_BLOCK_BROWSERLESS_TOKEN'),
            'timeout'    => (int) env('PDF_BLOCK_BROWSERLESS_TIMEOUT', 30),
            'wait_until' => env('PDF_BLOCK_BROWSERLESS_WAIT_UNTIL', 'load'),
            'reject_resource_types'  => [ /* ... */ ],
            'reject_request_pattern' => [ /* trackers bloqueados */ ],
        ],
    ],

    'fonts' => [
        'local_fonts' => [ /* 20 famílias corporativas */ ],
    ],
];
```

## Publicar views (opcional)

```bash
php artisan vendor:publish --tag=pdf-block-views
```

Copia os Blade templates para `resources/views/vendor/pdf-block/`:

```
vendor/pdf-block/
├── document.blade.php     # Layout principal
├── stripe.blade.php       # Stripe (faixa horizontal)
├── structure.blade.php    # Structure (container de colunas)
├── block.blade.php        # Router de bloco
└── blocks/
    ├── text.blade.php     # TipTap JSON → HTML
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

## Variáveis de ambiente

### Driver `local`

```dotenv
PDF_BLOCK_DRIVER=local
PDF_BLOCK_CHROME_PATH=/usr/bin/chromium
PDF_BLOCK_TIMEOUT=30
```

### Driver `browserless`

```dotenv
PDF_BLOCK_DRIVER=browserless
PDF_BLOCK_BROWSERLESS_URL=http://browserless:3000
PDF_BLOCK_BROWSERLESS_TOKEN=              # opcional
PDF_BLOCK_BROWSERLESS_TIMEOUT=30
PDF_BLOCK_BROWSERLESS_WAIT_UNTIL=load     # 'load' | 'domcontentloaded' | 'networkidle0'
```

---

## Verificação

```bash
php artisan tinker
```

```php
$renderer = app(\PdfBlock\Laravel\PdfBlockRenderer::class);

$html = $renderer->toHtml([
    'id'            => 'test',
    'version'       => '2.0.0',
    'meta'          => ['title' => 'Teste', 'locale' => 'pt-BR', 'tags' => []],
    'pageSettings'  => [
        'paperSize'   => ['preset' => 'a4', 'width' => 210, 'height' => 297],
        'orientation' => 'portrait',
        'margins'     => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20],
    ],
    'globalStyles'  => [
        'pageBackground'    => '#ffffff',
        'contentBackground' => '#ffffff',
        'defaultFontColor'  => '#333333',
    ],
    'blocks'        => [],
]);

echo substr($html, 0, 200);
```

Para validar o driver ativo sem gerar PDF completo, o sandbox expõe dois endpoints de diagnóstico — veja [quick-start.md](quick-start.md#diagnóstico).

---

## Troubleshooting

### Driver `local`: Chromium retorna SIGTRAP / crashpad

Acontece em `www-data` sem `CAP_SYS_PTRACE`. Duas saídas:

1. **Concede a capability ao `chrome_crashpad_handler`** (requer `libcap2-bin`):
   ```dockerfile
   RUN apt-get install -y libcap2-bin \
       && setcap cap_sys_ptrace+ep /usr/lib/chromium/chrome_crashpad_handler
   ```
2. **Usa o driver `browserless`** — o Chromium fica em outro container
   e o problema deixa de existir.

### Driver `local`: `DevToolsActivePort file doesn't exist`

Falta shared memory no container:

```yaml
services:
  app:
    shm_size: '256m'
```

Ou remova a necessidade com `--disable-dev-shm-usage` (já incluso nos defaults).

### Driver `browserless`: HTTP 408 / timeout no `setContent`

Geralmente é o ad-blocker do Browserless esperando listas de filtros. Garanta:

```yaml
environment:
  DEFAULT_BLOCK_ADS: "false"
```

O bloqueio de trackers é feito no lado do driver (`reject_request_pattern`),
sem dependência de DNS externo.

### Driver `browserless`: container OOM-killed (exit 137)

Chromium gasta `~280–320 MB` em builds pesados. Aumente `mem_limit` do
container `browserless` e revise `NODE_OPTIONS=--max-old-space-size=...`.
Com um PDF de 1–5 páginas típico, 336 MB é o suficiente.

### Driver `browserless`: HTTP 404 "No matching HTTP route handler"

Acontecia com clientes HTTP que emitiam request-line em absolute-form. O driver
atual usa libcurl nativo com HTTP/1.1 em origin-form, então esse erro não
aparece — se surgir, cheque se um proxy reverso (nginx, Traefik) está
reescrevendo a URL pra absolute-form antes de chegar no Browserless.

---

## Próximos Passos

- [Início Rápido](quick-start.md) — Uso básico e exemplos de integração
- [Imagem Docker `pdf-block-browserless`](image-build.md) — Build, publicação no GHCR e fontes
- [Blade Rendering Guide](blade-rendering-guide.md) — Referência técnica detalhada
# Instalação — pdf-block/laravel

Guia completo de instalação e configuração do pacote Laravel para renderização de PDFs a partir da DSL do `@pdf-block/react`.

## Requisitos

| Requisito | Versão |
|---|---|
| PHP | ≥ 8.2 |
| Laravel | 11.x ou 12.x |
| Chrome / Chromium | Instalado no servidor |

> **Nota:** Este pacote usa [chrome-php/chrome](https://github.com/chrome-php/chrome) para comunicação direta com o Chrome via DevTools Protocol. **Não** requer Node.js nem Puppeteer.

---

## Instalação

```bash
composer require pdf-block/laravel
```

O service provider é registrado automaticamente via [Laravel Package Discovery](https://laravel.com/docs/packages#package-discovery).

---

## Dependências

### Dependências PHP (instaladas automaticamente)

| Pacote | Descrição |
|---|---|
| `chrome-php/chrome` ^1.10 | Comunicação com Chrome headless via DevTools Protocol |
| `ueberdosis/tiptap-php` ^1.0\|^2.0 | Conversão de TipTap JSON → HTML (blocos de texto) |
| `illuminate/support` ^11.0\|^12.0 | Helpers do Laravel |
| `illuminate/view` ^11.0\|^12.0 | Engine Blade para templates |

### Chrome / Chromium no servidor

O pacote precisa de um binário Chrome ou Chromium instalado no sistema operacional:

#### Ubuntu / Debian

```bash
sudo apt update
sudo apt install -y chromium-browser
# ou
sudo apt install -y chromium
```

#### Alpine (Docker)

```dockerfile
RUN apk add --no-cache chromium
```

#### Amazon Linux / CentOS

```bash
sudo yum install -y chromium
```

#### macOS (desenvolvimento)

```bash
brew install --cask chromium
```

#### Verificar instalação

```bash
which chromium || which chromium-browser || which google-chrome
# Deve retornar o caminho do binário, ex: /usr/bin/chromium
```

---

## Configuração

### Publicar arquivo de config (opcional)

```bash
php artisan vendor:publish --tag=pdf-block-config
```

Cria `config/pdf-block.php`:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chrome / Chromium Binary
    |--------------------------------------------------------------------------
    |
    | Caminho para o executável do Chrome ou Chromium.
    | chrome-php se comunica via DevTools Protocol — Node.js NÃO é necessário.
    |
    */

    'chrome_path' => env('PDF_BLOCK_CHROME_PATH', '/usr/bin/chromium'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Tempo máximo em segundos para aguardar a renderização do PDF.
    |
    */

    'timeout' => (int) env('PDF_BLOCK_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Chrome Arguments
    |--------------------------------------------------------------------------
    |
    | Flags CLI passadas ao Chromium.
    | Os padrões abaixo funcionam na maioria dos servidores Linux e containers.
    |
    */

    'chrome_args' => [
        '--no-sandbox',
        '--disable-gpu',
        '--disable-dev-shm-usage',
    ],

];
```

### Variáveis de Ambiente

```dotenv
# Caminho do binário Chrome/Chromium
PDF_BLOCK_CHROME_PATH=/usr/bin/chromium

# Timeout de renderização (segundos)
PDF_BLOCK_TIMEOUT=30
```

---

## Publicar Views (opcional)

Para customizar os templates Blade de renderização:

```bash
php artisan vendor:publish --tag=pdf-block-views
```

Os templates são copiados para `resources/views/vendor/pdf-block/`:

```
resources/views/vendor/pdf-block/
├── document.blade.php     # Layout principal do documento
├── stripe.blade.php       # Stripe (faixa horizontal)
├── structure.blade.php    # Structure (container de colunas)
├── block.blade.php        # Router que despacha para o template do bloco
└── blocks/
    ├── text.blade.php     # Bloco de texto (TipTap JSON → HTML)
    ├── image.blade.php    # Bloco de imagem
    ├── button.blade.php   # Bloco de botão
    ├── divider.blade.php  # Linha divisória
    ├── spacer.blade.php   # Espaço vertical
    ├── banner.blade.php   # Banner com imagem de fundo
    ├── table.blade.php    # Tabela
    ├── qrcode.blade.php   # QR Code
    ├── chart.blade.php    # Gráfico de barras (CSS)
    └── pagebreak.blade.php # Quebra de página
```

---

## Service Provider

O `PdfBlockServiceProvider` registra automaticamente:

1. **Singleton** `PdfBlockRenderer` — injetável via DI com as configurações do `config/pdf-block.php`
2. **Alias** `pdf-block` — permite `app('pdf-block')`
3. **Views** no namespace `pdf-block` — `pdf-block::document`, `pdf-block::stripe`, etc.

---

## Verificação

Teste se tudo está configurado corretamente:

```bash
php artisan tinker
```

```php
$renderer = app(\PdfBlock\Laravel\PdfBlockRenderer::class);
$html = $renderer->toHtml([
    'id' => 'test',
    'version' => '2.0.0',
    'meta' => ['title' => 'Teste', 'description' => '', 'locale' => 'pt-BR', 'tags' => []],
    'pageSettings' => [
        'paperSize' => ['preset' => 'a4', 'width' => 210, 'height' => 297],
        'orientation' => 'portrait',
        'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20],
        'defaultFontFamily' => 'Inter, sans-serif',
    ],
    'globalStyles' => [
        'pageBackground' => '#ffffff',
        'contentBackground' => '#ffffff',
        'defaultFontColor' => '#333333',
    ],
    'blocks' => [],
]);
echo strlen($html) . " bytes de HTML gerados.\n";
```

---

## Solução de Problemas

### Chrome não encontrado

```
Could not find Chrome binary
```

Verifique o caminho do binário:

```bash
which chromium
# Configure no .env:
PDF_BLOCK_CHROME_PATH=/caminho/correto/chromium
```

### Erro de sandbox no container Docker

```
Running as root without --no-sandbox is not supported
```

Certifique-se de que `--no-sandbox` está nos `chrome_args` (padrão do config).

### Timeout de renderização

```
Timeout reached while waiting for page to load
```

Aumente o timeout no `.env`:

```dotenv
PDF_BLOCK_TIMEOUT=60
```

### Shared memory insuficiente (Docker)

```
DevToolsActivePort file doesn't exist
```

Adicione ao container Docker:

```yaml
# docker-compose.yml
services:
  app:
    shm_size: '256m'
    # ou
    tmpfs:
      - /dev/shm:rw,nosuid,nodev,noexec,size=256m
```

---

## Próximos Passos

- [Início Rápido](quick-start.md) — Uso básico e exemplos de integração
- [Templates Blade](templates.md) — Customização dos templates de renderização
- [Blade Rendering Guide](blade-rendering-guide.md) — Referência técnica detalhada
