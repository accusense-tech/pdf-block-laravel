<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver de PDF
    |--------------------------------------------------------------------------
    |
    | Define qual driver será usado para gerar PDFs.
    |
    |   'local'       — Chromium local via CLI (padrão). Requer o binário
    |                   instalado no servidor ou container.
    |   'browserless' — Serviço HTTP compatível com Browserless v1
    |                   (auto-hospedado ou browserless.io). Elimina o
    |                   Chromium do container PHP.
    |
    | Valor: env PDF_BLOCK_DRIVER
    |
    */

    'driver' => env('PDF_BLOCK_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Configuração por driver
    |--------------------------------------------------------------------------
    */

    'drivers' => [

        'local' => [
            /*
             | Caminho para o binário do Chromium ou Google Chrome.
             */
            'chrome_path' => env('PDF_BLOCK_CHROME_PATH', '/usr/bin/chromium'),

            /*
             | Tempo máximo (em segundos) para aguardar a renderização.
             */
            'timeout' => (int) env('PDF_BLOCK_TIMEOUT', 30),

            /*
             | Flags CLI passadas ao Chromium.
             |
             | --disable-crash-reporter e --disable-breakpad reduzem problemas
             | de permissão (ptrace) com o crashpad_handler em ambientes sem
             | CAP_SYS_PTRACE (ex.: www-data via PHP-FPM).
             |
             | NÃO incluir --user-data-dir aqui: o driver injeta um path único
             | por requisição para evitar contenção de SingletonLock entre
             | requisições concorrentes.
             |
             | NÃO incluir --run-all-compositor-stages-before-draw: essa flag
             | de debug dobra o tempo de render e não é necessária para PDF.
             */
            'chrome_args' => [
                '--headless=new',
                '--ozone-platform=headless',
                '--no-sandbox',
                '--disable-gpu',
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
            ],
        ],

        'browserless' => [
            /*
             | URL base do serviço Browserless.
             | Ex.: http://browserless:3000  (Docker interno)
             |       https://chrome.browserless.io  (managed)
             */
            'url' => env('PDF_BLOCK_BROWSERLESS_URL', 'http://browserless:3000'),

            /*
             | Token de autenticação do Browserless (opcional).
             | Deixe null para instâncias sem autenticação (ex.: dev local).
             | Em produção, defina TOKEN no container e esta variável no app.
             */
            'token' => env('PDF_BLOCK_BROWSERLESS_TOKEN'),

            /*
             | Tempo máximo (em segundos) para aguardar a resposta HTTP.
             */
            'timeout' => (int) env('PDF_BLOCK_BROWSERLESS_TIMEOUT', 30),

            /*
             | waitUntil do Puppeteer. Default 'load' — seguro porque fontes
             | são 100% locais (fontconfig) e imagens são aguardadas pelo
             | próprio evento load do browser.
             |
             | Valores aceitos: 'load', 'domcontentloaded', 'networkidle0',
             | 'networkidle2'. Use 'networkidle0' apenas se o template fizer
             | requests XHR/fetch assíncronos após o load inicial.
             */
            'wait_until' => env('PDF_BLOCK_BROWSERLESS_WAIT_UNTIL', 'load'),

            /*
             | Flags CLI repassadas ao Chromium por requisição via launch.args.
             | Perfil padrão é otimizado para baixo consumo de RAM (<150 MB
             | por page). Deixe null/omit para usar o default do driver.
             |
             | --js-flags=--max-old-space-size=128 limita o heap do V8 do
             | renderer. Se você gerar PDFs muito grandes (100+ páginas com
             | imagens) e vir OOM, aumente para 256 ou 512.
             */
            'launch_args' => null,

            /*
             | Tipos de recurso que o Puppeteer cancela ANTES de ir para a
             | rede. Reduz tempo de geração e pico de CPU/memória.
             |
             | Tipos suportados: document, stylesheet, image, media, font,
             | script, texttrack, xhr, fetch, eventsource, websocket,
             | manifest, other.
             |
             | ⚠️ IMPORTANTE:
             |   - 'image' NÃO está na lista: imagens S3/CDN são essenciais
             |     para o conteúdo do PDF.
             |   - 'font' NÃO está na lista: fontes locais já não disparam
             |     rede, mas bloquear quebraria @font-face customizado.
             |   - 'stylesheet' e 'script' NÃO estão na lista: templates
             |     corporativos podem depender de CSS/JS externo.
             */
            'reject_resource_types' => [
                'media',
                'websocket',
                'eventsource',
                'manifest',
                'texttrack',
                'other',
            ],

            /*
             | Padrões regex (JavaScript) para bloquear URLs por domínio.
             | Default bloqueia trackers/analytics conhecidos — NUNCA afeta
             | imagens S3, CDNs internos ou recursos legítimos.
             |
             | Para desabilitar o bloqueio, defina como []. Para customizar,
             | sobrescreva a lista inteira.
             */
            'reject_request_pattern' => [
                'google-analytics\\.com',
                'googletagmanager\\.com',
                'doubleclick\\.net',
                'facebook\\.(net|com)/tr',
                'hotjar\\.com',
                'mixpanel\\.com',
                'segment\\.(io|com)',
                'fullstory\\.com',
                'clarity\\.ms',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Carregamento de fontes
    |--------------------------------------------------------------------------
    |
    | O renderer usa exclusivamente fontes locais instaladas no sistema
    | (ex.: imagem Docker do Browserless via Dockerfile.browserless).
    | Zero tráfego externo para CDNs — latência previsível e RAM baixa.
    |
    | Se precisar adicionar famílias, atualize `local_fonts` abaixo,
    | edite `packages/laravel/docker/install-fonts.sh` para baixar os
    | arquivos no build da imagem, e atualize o fontCatalog.ts do pacote React.
    |
    */

    'fonts' => [
        /*
        |--------------------------------------------------------------------------
        | Fontes locais (instaladas no container / servidor)
        |--------------------------------------------------------------------------
        |
        | Famílias presentes no sistema de fontes do renderer.
        | Estas listas DEVEM estar sincronizadas com:
        |   - packages/react/src/fontCatalog.ts  (GOOGLE_FONTS)
        |   - packages/laravel/docker/install-fonts.sh
        |
        */

        'local_fonts' => [
            // Sans
            'Inter',
            'Roboto',
            'Open Sans',
            'Lato',
            'Source Sans 3',
            'Noto Sans',
            'Work Sans',
            'DM Sans',
            'Montserrat',
            // Serif (default = Spectral)
            'Spectral',
            'Merriweather',
            'Lora',
            'Source Serif 4',
            'PT Serif',
            'EB Garamond',
            'Libre Baskerville',
            'Playfair Display',
            'Noto Serif',
            // Display
            'Oswald',
            'Khand',
            // Mono
            'Roboto Mono',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Motor de paginação (migração — ver HANDOFF-PAGINATOR.md)
    |--------------------------------------------------------------------------
    |
    | Seam de COEXISTÊNCIA da reforma do paginador. Controla como o PDF paginado
    | (só v3) decide as quebras de página:
    |
    |   'css' — (PADRÃO) o Chromium pagina nativamente via CSS Paged Media
    |           (@page + margin-boxes + target-counter). Estável. Cabeçalho/rodapé
    |           saem como texto de margin-box (@page nomeada por seção) — NÃO
    |           renderiza o flow rico de um FurnitureComponent document-level.
    |   'js'  — um SCRIPT de paginação embutido no próprio blade (document.blade.php,
    |           NÃO o @pdf-block/paginator do editor) mede o conteúdo no browser e
    |           MATERIALIZA folhas físicas (.pdf-page) por clip-window; o Chromium só
    |           faz HTML→PDF. É o único motor que renderiza cabeçalho/rodapé
    |           document-level com flow (clona a banda por página, com showOn e
    |           tokens {page}/{pages}). Por isso, quando o documento tem mobília
    |           document-level habilitada, o renderer força 'js' automaticamente
    |           (ver PdfBlockRenderer::hasDocumentFurniture).
    |
    |           Limitações do 'js' (vs @page CSS): as quebras "keep-together" são
    |           aproximadas por avoidSpans (heurística de blocos indivisíveis), não
    |           idênticas ao motor nativo do Chromium; requer JS habilitado no
    |           driver (Local/Browserless executam scripts por padrão). O sandbox já
    |           usa 'js' em testes; escolha o motor conforme o caso.
    |
    | Valor: env PDF_BLOCK_PAGINATION_ENGINE
    |
    */

    'pagination' => [
        'engine' => env('PDF_BLOCK_PAGINATION_ENGINE', 'css'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Renderers de bloco BUILT-IN (mapa type → view Blade, por modo)
    |--------------------------------------------------------------------------
    |
    | Fonte única do "roteamento" dos blocos internos. O ServiceProvider
    | pré-registra um BladeBlockRenderer por (modo, tipo) no
    | BlockRendererRegistry a partir deste mapa — foi o que aposentou os dois
    | @switch gigantes de `block.blade.php` e `email/block.blade.php`.
    |
    | Precedência: um plugin do app registrado para o MESMO (modo, tipo) VENCE
    | o built-in (o provider só registra quando o registry ainda não tem aquela
    | chave), então dá para sobrescrever `text`/`table`/etc. sem tocar no pacote.
    |
    | Formato do valor:
    |   - string           → nome da view; o bloco entra como `$block`.
    |   - ['view'=>…, 'as'=>…] → injeta o bloco sob outra variável (a `structure`
    |     aninhada usa `structure`, que o template recursivo lê).
    |
    | Adicionar um bloco novo server-side = 1 blade + 1 linha aqui (o caminho
    | de plugin via BlockRendererRegistry->register() continua disponível para
    | blocos de app que precisam de lógica PHP).
    |
    */

    'blocks' => [

        'pdf' => [
            // `structure` aninhada em coluna: render recursivo (lê $structure).
            'structure' => ['view' => 'pdf-block::structure', 'as' => 'structure'],
            'text'      => 'pdf-block::blocks.text',
            'image'     => 'pdf-block::blocks.image',
            'figure'    => 'pdf-block::blocks.figure',
            'toc'       => 'pdf-block::blocks.toc',
            'button'    => 'pdf-block::blocks.button',
            'divider'   => 'pdf-block::blocks.divider',
            'spacer'    => 'pdf-block::blocks.spacer',
            'table'     => 'pdf-block::blocks.table',
            'qrcode'    => 'pdf-block::blocks.qrcode',
            'svg'       => 'pdf-block::blocks.svg',
            'chart'     => 'pdf-block::blocks.chart',
            'pagebreak' => 'pdf-block::blocks.pagebreak',
        ],

        'email' => [
            'text'    => 'pdf-block::email.blocks.text',
            'image'   => 'pdf-block::email.blocks.image',
            'button'  => 'pdf-block::email.blocks.button',
            'divider' => 'pdf-block::email.blocks.divider',
            'spacer'  => 'pdf-block::email.blocks.spacer',
            'table'   => 'pdf-block::email.blocks.table',
            'banner'  => 'pdf-block::email.blocks.banner',
            // `qrcode` reaproveita o template de imagem no e-mail (igual ao @switch).
            'qrcode'  => 'pdf-block::email.blocks.image',
            // Sem forma em e-mail — saída vazia (preserva o no-op do @switch antigo).
            'pagebreak' => 'pdf-block::email.blocks.noop',
            'chart'     => 'pdf-block::email.blocks.noop',
            'svg'       => 'pdf-block::email.blocks.noop',
        ],

    ],

];
