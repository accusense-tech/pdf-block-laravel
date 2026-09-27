<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

require_once __DIR__ . '/SchemaDefaults.gen.php';

/**
 * CSS helper functions — direct port of the TypeScript utils.ts helpers.
 *
 * Every function here produces the same CSS output as its JS counterpart,
 * ensuring visual parity between the React editor and the server-rendered PDF.
 */
class StyleHelpers
{
    // ─── Números na CSS ──────────────────────────────────────

    /** Casas decimais dos campos de RAZÃO (opacidade) — ver cssNum(). */
    private const RATIO_DECIMALS = 3;

    /**
     * Número → string, com casas decimais FIXAS. Espelho EXATO de `cssNum` do
     * TypeScript (utils.ts) — os dois têm de produzir o mesmo texto.
     *
     * Existe porque PHP e JS convertem número→string com regras diferentes e o
     * documento pode trazer qualquer float: `33.333333333333336` sai como
     * `33.333333333333` aqui (precision=14) e inteiro no canvas; `1e21` vira
     * `1.0E+21` de um lado e `1e+21` do outro — nenhum é comprimento CSS válido.
     * O sintoma seria um gradiente com ÂNGULO diferente no PDF e no editor, sem
     * erro em lugar nenhum. Travado pelo golden de paridade de estilo.
     *
     * Meio arredonda para LONGE do zero, a parte inteira nunca sai em notação
     * científica e os zeros à direita são podados — as três coisas que o TS faz.
     */
    public static function cssNum(int|float $n, int $decimals = 1): string
    {
        $n = (float) $n;
        if (!is_finite($n)) {
            return '0';
        }
        $p = 10 ** $decimals;
        $sign = $n < 0 ? '-' : '';
        $r = round(abs($n) * $p) / $p;
        $i = floor($r);
        $d = (int) round(($r - $i) * $p);
        if ($d >= $p) {
            $i += 1;
            $d = 0;
        }
        $int = number_format($i, 0, '.', '');
        $frac = $decimals > 0 ? rtrim(str_pad((string) $d, $decimals, '0', STR_PAD_LEFT), '0') : '';

        return $frac !== '' ? "{$sign}{$int}.{$frac}" : "{$sign}{$int}";
    }

    // ─── Primitives ──────────────────────────────────────────

    public static function edgeToCSS(array $edge, string $unit = 'px'): string
    {
        $t = self::cssNum($edge['top'] ?? 0);
        $r = self::cssNum($edge['right'] ?? 0);
        $b = self::cssNum($edge['bottom'] ?? 0);
        $l = self::cssNum($edge['left'] ?? 0);

        return "{$t}{$unit} {$r}{$unit} {$b}{$unit} {$l}{$unit}";
    }

    public static function cornersToCSS(array $c): string
    {
        $tl = self::cssNum($c['topLeft'] ?? 0);
        $tr = self::cssNum($c['topRight'] ?? 0);
        $br = self::cssNum($c['bottomRight'] ?? 0);
        $bl = self::cssNum($c['bottomLeft'] ?? 0);

        return "{$tl}px {$tr}px {$br}px {$bl}px";
    }

    public static function borderSideToCSS(array $b): string
    {
        if (($b['style'] ?? 'none') === 'none' || ($b['width'] ?? 0) == 0) {
            return 'none';
        }

        $color = $b['color'] ?? '#000000';
        $w = self::cssNum($b['width'] ?? 0);

        return "{$w}px {$b['style']} {$color}";
    }

    public static function shadowToCSS(array $s): string
    {
        if (empty($s['enabled'])) {
            return 'none';
        }

        $ox = self::cssNum($s['offsetX'] ?? 0);
        $oy = self::cssNum($s['offsetY'] ?? 0);
        $blur = self::cssNum($s['blur'] ?? 0);
        $spread = self::cssNum($s['spread'] ?? 0);
        $color = $s['color'] ?? 'rgba(0,0,0,0.15)';

        return "{$ox}px {$oy}px {$blur}px {$spread}px {$color}";
    }

    public static function backgroundToCSS(array $bg): string
    {
        return match ($bg['type'] ?? 'solid') {
            'solid' => "background-color:{$bg['color']};",

            'image' => implode('', [
                // `safeUrl`, não `e()`: o Blade emite o atributo `style` com
                // `{{ }}`, que já escapa. O `e()` aqui era um SEGUNDO escape —
                // `?w=100&h=50` virava `&amp;amp;h=50` no HTML e o navegador
                // pedia uma URL literalmente errada. Toda imagem de fundo com
                // query string (presigned S3, CDN) sumia do PDF.
                // `safeUrl` limpa caracteres de controle e barra esquema
                // perigoso; `cssUrl` neutraliza o que FECHA o `url(` — as duas
                // proteções são de camadas diferentes e nenhuma cobre a outra.
                "background-image:url(" . self::cssUrl(self::safeUrl((string) ($bg['url'] ?? ''), true)) . ");",
                'background-size:' . (($bg['size'] ?? 'cover') === 'custom' ? 'auto' : ($bg['size'] ?? 'cover')) . ';',
                "background-repeat:" . ($bg['repeat'] ?? 'no-repeat') . ";",
                "background-position:" . ($bg['positionX'] ?? 'center') . " " . ($bg['positionY'] ?? 'center') . ";",
            ]),

            'gradient' => 'background-image:' . self::gradientCSS($bg) . ';',

            default => '',
        };
    }

    public static function gradientCSS(array $bg): string
    {
        $stops = collect($bg['stops'] ?? [])
            ->map(fn(array $s) => ($s['color'] ?? 'transparent') . ' ' . self::cssNum($s['position'] ?? 0) . '%')
            ->implode(', ');

        $angle = self::cssNum($bg['angle'] ?? 0);

        return ($bg['gradientType'] ?? 'linear') === 'linear'
            ? "linear-gradient({$angle}deg, {$stops})"
            : "radial-gradient(circle, {$stops})";
    }

    // ─── Normalização defensiva (espelho de normalizeStyles do TS) ──

    /**
     * Completa um BlockStyles PARCIAL ou AUSENTE com os defaults, em profundidade.
     * Espelho 1:1 de `normalizeStyles` do React (store/normalize.ts) — garante
     * paridade editor↔PDF quando a DSL vem ESPARSA (cada bloco carrega só o que
     * difere do default). Sem isto, um sub-objeto parcial (ex.: `padding:{top:8}`)
     * geraria CSS quebrado.
     *
     * Continua sendo NORMALIZADOR, campo a campo — não "devolve o default e
     * mescla". O que mudou é só a ORIGEM das constantes: `SchemaDefaults`, a
     * mesma fonte do TS.
     *
     * @param  array<string, mixed>  $styles
     * @return array<string, mixed>
     */
    public static function normalizeStyles(array $styles): array
    {
        $d = SchemaDefaults::blockStyles();

        $edge = fn($e, array $def) => is_array($e)
            ? ['top' => $e['top'] ?? $def['top'], 'right' => $e['right'] ?? $def['right'], 'bottom' => $e['bottom'] ?? $def['bottom'], 'left' => $e['left'] ?? $def['left']]
            : $def;

        $defaultSide = $d['border']['top'];
        $side = fn($b) => is_array($b)
            ? ['width' => is_numeric($b['width'] ?? null) ? $b['width'] : $defaultSide['width'], 'style' => $b['style'] ?? $defaultSide['style'], 'color' => $b['color'] ?? $defaultSide['color']]
            : $defaultSide;

        $border = $styles['border'] ?? null;
        $radius = $d['borderRadius'];

        return [
            'padding' => $edge($styles['padding'] ?? null, $d['padding']),
            'margin' => $edge($styles['margin'] ?? null, $d['margin']),
            'border' => is_array($border)
                ? ['top' => $side($border['top'] ?? null), 'right' => $side($border['right'] ?? null), 'bottom' => $side($border['bottom'] ?? null), 'left' => $side($border['left'] ?? null)]
                : $d['border'],
            'borderRadius' => is_array($styles['borderRadius'] ?? null)
                ? ['topLeft' => $styles['borderRadius']['topLeft'] ?? $radius['topLeft'], 'topRight' => $styles['borderRadius']['topRight'] ?? $radius['topRight'], 'bottomRight' => $styles['borderRadius']['bottomRight'] ?? $radius['bottomRight'], 'bottomLeft' => $styles['borderRadius']['bottomLeft'] ?? $radius['bottomLeft']]
                : $radius,
            'background' => is_array($styles['background'] ?? null)
                ? $styles['background']
                : $d['background'],
            'shadow' => is_array($styles['shadow'] ?? null)
                ? array_merge($d['shadow'], $styles['shadow'])
                : $d['shadow'],
            'opacity' => is_numeric($styles['opacity'] ?? null) ? $styles['opacity'] : $d['opacity'],
        ];
    }

    // ─── Block-level composite ───────────────────────────────

    /**
     * Convert a BlockStyles array into a single inline CSS string.
     * Mirrors blockStylesToCSS() from utils.ts.
     */
    public static function blockStyles(array $styles): string
    {
        // Completa qualquer styles parcial/ausente antes de serializar (DSL esparsa).
        $styles = self::normalizeStyles($styles);
        $border = $styles['border'] ?? [];

        return implode('', [
            'padding:' . self::edgeToCSS($styles['padding'] ?? ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]) . ';',
            'margin:' . self::edgeToCSS($styles['margin'] ?? ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]) . ';',
            'border-top:' . self::borderSideToCSS($border['top'] ?? ['width' => 0, 'style' => 'none', 'color' => '#000']) . ';',
            'border-right:' . self::borderSideToCSS($border['right'] ?? ['width' => 0, 'style' => 'none', 'color' => '#000']) . ';',
            'border-bottom:' . self::borderSideToCSS($border['bottom'] ?? ['width' => 0, 'style' => 'none', 'color' => '#000']) . ';',
            'border-left:' . self::borderSideToCSS($border['left'] ?? ['width' => 0, 'style' => 'none', 'color' => '#000']) . ';',
            'border-radius:' . self::cornersToCSS($styles['borderRadius'] ?? ['topLeft' => 0, 'topRight' => 0, 'bottomRight' => 0, 'bottomLeft' => 0]) . ';',
            'box-shadow:' . self::shadowToCSS($styles['shadow'] ?? ['enabled' => false]) . ';',
            'opacity:' . self::cssNum($styles['opacity'] ?? 1, self::RATIO_DECIMALS) . ';',
            self::backgroundToCSS($styles['background'] ?? ['type' => 'solid', 'color' => 'transparent']),
        ]);
    }

    /**
     * Merge PROFUNDO de arrays associativos: `$source` vence nos campos que
     * define; o resto vem de `$target`. Listas (sequenciais) são SUBSTITUÍDAS
     * inteiras (não mescladas). Espelha `deepMerge` em utils.ts. Usado para
     * resolver estilos nomeados (styleRef) — ver FlowRender::resolveStyleRefs.
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function deepMerge(array $target, array $source): array
    {
        $result = $target;
        foreach ($source as $key => $val) {
            if (is_array($val) && ! array_is_list($val) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = self::deepMerge($result[$key], $val);
            } elseif ($val !== null) {
                $result[$key] = $val;
            }
        }

        return $result;
    }

    // ─── Fundo colorido (para evitar corte na quebra de página) ──

    /**
     * `true` quando o bloco tem fundo NÃO-transparente (cor sólida visível,
     * gradiente ou imagem). Um "card" colorido cortado por uma quebra de página
     * parece quebrado; por isso tratamos esses blocos como indivisíveis
     * (`break-inside: avoid`). Espelha `hasColoredBackground` em utils.ts.
     *
     * @param  array<string, mixed>  $styles
     */
    public static function hasColoredBackground(array $styles): bool
    {
        $bg = $styles['background'] ?? [];
        $type = $bg['type'] ?? 'solid';
        if ($type === 'gradient' || $type === 'image') {
            return true;
        }
        $color = strtolower(trim((string) ($bg['color'] ?? '')));

        return $color !== '' && $color !== 'transparent' && $color !== 'rgba(0,0,0,0)';
    }

    /**
     * Cor SÓLIDA equivalente a um fundo, para contextos que não suportam
     * gradiente/imagem (e-mail/VML). Sólido → sua cor; gradiente → 1º stop;
     * imagem/ausente → `$fallback`.
     *
     * @param  array<string, mixed>  $bg
     */
    public static function backgroundSolidColor(array $bg, string $fallback = 'transparent'): string
    {
        $type = $bg['type'] ?? 'solid';
        if ($type === 'solid') {
            $c = trim((string) ($bg['color'] ?? ''));

            return $c !== '' ? $c : $fallback;
        }
        if ($type === 'gradient' && is_array($bg['stops'] ?? null) && isset($bg['stops'][0]['color'])) {
            return (string) $bg['stops'][0]['color'];
        }

        return $fallback;
    }

    // ─── Migração do botão (schema antigo → styles.*) ────────────

    /**
     * Migra um botão do schema ANTIGO — fundo/cantos/borda/espaçamento eram
     * campos PRÓPRIOS (`bgColor`/`borderColor`/`borderWidth`/`borderRadius`/
     * `paddingH`/`paddingV`) — para o unificado, com o visual em `styles.*`
     * (igual aos demais blocos). Preserva a aparência já exibida (os valores
     * legados prevalecem); ausentes caem nos defaults ANTIGOS (azul/6px/12×24).
     * Idempotente. Espelha `migrateButtonBlock` em store/normalize.ts (client).
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function migrateButtonBlock(array $block): array
    {
        $styles = is_array($block['styles'] ?? null) ? $block['styles'] : [];

        // Seed dos defaults VISUAIS do botão nos campos AUSENTES (DSL esparsa), pois
        // o visual vive em styles e o botão pode chegar sem eles. Espelha
        // seedButtonVisualDefaults (client). Só toca campos ausentes → um botão
        // "ghost" (fundo transparente explícito) é preservado.
        if (! isset($styles['background'])) {
            $styles['background'] = ['type' => 'solid', 'color' => '#5b8cff'];
        }
        if (! isset($styles['borderRadius'])) {
            $styles['borderRadius'] = ['topLeft' => 6, 'topRight' => 6, 'bottomRight' => 6, 'bottomLeft' => 6];
        }
        if (! isset($styles['padding'])) {
            $styles['padding'] = ['top' => 12, 'right' => 24, 'bottom' => 12, 'left' => 24];
        }
        $block['styles'] = $styles;

        $hasLegacy = isset($block['bgColor']) || isset($block['paddingH']) || isset($block['paddingV'])
            || isset($block['borderRadius']) || isset($block['borderWidth']) || isset($block['borderColor']);
        if (! $hasLegacy) {
            return $block;
        }

        // Fundo: cor sólida legada → styles.background (default antigo #5b8cff).
        $bg = (isset($block['bgColor']) && $block['bgColor'] !== '') ? (string) $block['bgColor'] : '#5b8cff';
        $styles['background'] = ['type' => 'solid', 'color' => $bg];
        // Cantos: borderRadius próprio → styles.borderRadius (default antigo 6px).
        $styles['borderRadius'] = is_array($block['borderRadius'] ?? null)
            ? $block['borderRadius']
            : ['topLeft' => 6, 'topRight' => 6, 'bottomRight' => 6, 'bottomLeft' => 6];
        // Espaçamento interno: paddingH/paddingV → styles.padding (defaults 24×12).
        $ph = is_numeric($block['paddingH'] ?? null) ? (float) $block['paddingH'] : 24;
        $pv = is_numeric($block['paddingV'] ?? null) ? (float) $block['paddingV'] : 12;
        $styles['padding'] = ['top' => $pv, 'right' => $ph, 'bottom' => $pv, 'left' => $ph];
        // Borda: só converte se não houver borda de estilo já definida.
        $bd = is_array($styles['border'] ?? null) ? $styles['border'] : [];
        $bt = is_array($bd['top'] ?? null) ? $bd['top'] : [];
        $hasStyleBorder = ($bt['style'] ?? 'none') !== 'none' && ($bt['width'] ?? 0) > 0;
        if (! $hasStyleBorder && is_numeric($block['borderWidth'] ?? null) && (float) $block['borderWidth'] > 0) {
            $side = ['width' => (float) $block['borderWidth'], 'style' => 'solid', 'color' => (string) ($block['borderColor'] ?? '#000000')];
            $styles['border'] = ['top' => $side, 'right' => $side, 'bottom' => $side, 'left' => $side];
        }

        $block['styles'] = $styles;
        unset($block['bgColor'], $block['borderColor'], $block['borderWidth'], $block['borderRadius'], $block['paddingH'], $block['paddingV']);

        return $block;
    }

    // ─── Fundo do BANNER ─────────────────────────────────────

    /**
     * `background-size` do fundo do banner. Espelho EXATO de
     * `core/imageAdjust.bannerBackgroundSize` (TS).
     *
     * No MOSAICO (`auto`) o valor NÃO pode ser o `auto` do CSS: `auto` é o
     * tamanho NATURAL da imagem, e uma foto comum (1200px+) é maior que o
     * banner — um único ladrilho cobria tudo e o "Mosaico" saía como um recorte
     * ampliado, sem repetir nada. A largura em % da caixa garante ladrilho
     * menor que o banner (altura `auto` preserva a proporção).
     */
    public static function bannerBackgroundSize(?string $size, mixed $tilePct): string
    {
        $size = $size ?? 'cover';
        if ($size !== 'auto') {
            return $size;
        }
        $pct = is_numeric($tilePct) && (float) $tilePct > 0 ? min((float) $tilePct, 100) : 25;

        return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '% auto';
    }

    /** `background-repeat` do fundo do banner: só o mosaico ladrilha. */
    public static function bannerBackgroundRepeat(?string $size): string
    {
        return ($size ?? 'cover') === 'auto' ? 'repeat' : 'no-repeat';
    }

    // ─── Justify helper ──────────────────────────────────────

    public static function justifyCSS(string $alignment): string
    {
        return match ($alignment) {
            'left'  => 'flex-start',
            'right' => 'flex-end',
            default => 'center',
        };
    }

    // ─── Font-family normalizer ───────────────────────────────

    /**
     * Ensure every token in a CSS font-family string that contains spaces is
     * wrapped in single quotes so Chromium (PDF renderer) resolves it correctly.
     *
     * Without quoting, "Open Sans, sans-serif" is parsed as three unknown tokens
     * ("Open", "Sans", "sans-serif") and Chromium silently falls back to the
     * generic family, never loading the installed font.
     *
     * Examples:
     *   "Open Sans, sans-serif"    → "'Open Sans', sans-serif"
     *   "Roboto, sans-serif"       → "Roboto, sans-serif"  (single-word, unchanged)
     *   "'Source Serif 4', serif"  → "'Source Serif 4', serif"  (already quoted, no double-wrap)
     *   "Spectral, serif"          → "Spectral, serif"
     */
    public static function normalizeFontFamily(string $family): string
    {
        $tokens = explode(',', $family);

        $normalized = array_map(static function (string $token): string {
            $t = trim($token);

            // Already quoted with single or double quotes — leave as-is.
            if (
                (str_starts_with($t, "'") && str_ends_with($t, "'")) ||
                (str_starts_with($t, '"') && str_ends_with($t, '"'))
            ) {
                return $t;
            }

            // Multi-word font name (contains a space) — wrap in single quotes.
            if (str_contains($t, ' ')) {
                return "'{$t}'";
            }

            return $t;
        }, $tokens);

        // Genéricos DETERMINÍSTICOS (espelho 1:1 de fonts.ts do editor):
        // `cursive`/`fantasy` variam por fontconfig; ancoramos na família que a
        // imagem browserless resolve (Comic Sans MS / Impact, mscorefonts) E no
        // aproximado web que o editor usa (Comic Neue / Anton) — a MESMA pilha
        // nos dois lados faz qualquer visualizador cair na mesma fonte; no
        // container o aproximado é ignorado (a concreta vem antes).
        $genericConcrete = [
            'cursive' => ["'Comic Sans MS'", "'Comic Neue'"],
            'fantasy' => ['Impact', 'Anton'],
        ];
        $bare = static fn (string $t): string => strtolower(trim(trim($t), "'\""));
        $present = array_map($bare, $normalized);
        $expanded = [];
        foreach ($normalized as $t) {
            foreach ($genericConcrete[$bare($t)] ?? [] as $concrete) {
                if (! in_array($bare($concrete), $present, true)) {
                    $expanded[] = $concrete;
                    $present[] = $bare($concrete);
                }
            }
            $expanded[] = $t;
        }

        return implode(', ', $expanded);
    }

    // ─── URL sanitizer (segurança) ────────────────────────────

    /**
     * Caracteres que ENCERRAM ou reabrem um `url(...)` sem aspas. Ver `cssUrl`.
     * `%hh` mantém a URL equivalente para o servidor HTTP e inerte para o CSS.
     */
    private const CSS_URL_ESCAPES = [
        '\\' => '%5C', '(' => '%28', ')' => '%29',
        "'" => '%27', '"' => '%22', '<' => '%3C', '>' => '%3E',
    ];

    /**
     * URL pronta para entrar num `url(...)` de CSS. Espelho EXATO de `cssUrl` do
     * TypeScript (utils.ts) — os dois têm de produzir o mesmo texto, senão o
     * fundo do canvas e o do PDF apontam para endereços diferentes.
     *
     * `safeUrl` cuida do ESQUEMA; falta o contexto. Dentro de `url(` sem aspas um
     * `)` fecha a função e o resto da string vira DECLARAÇÃO: uma URL de imagem
     * como `…/i.png);background:red;/*` reescrevia o estilo do documento, e num
     * renderizador headless um `url(https://…/?vazamento)` dispara a requisição a
     * partir do servidor. Percent-encoding resolve sem trocar a imagem de lugar.
     *
     * O caminho do PHP compõe os dois (`cssUrl(safeUrl($u))`); o do TypeScript só
     * aplica este — a sanitização de esquema é defesa do SERVIDOR, que entrega o
     * HTML ao browserless. Para toda URL de verdade os dois lados coincidem, e é
     * isso que o golden de estilo (49 fixtures) cobre.
     */
    public static function cssUrl(string $url): string
    {
        return strtr($url, self::CSS_URL_ESCAPES);
    }

    /**
     * Sanitiza uma URL para uso em `href`/`src`, bloqueando esquemas perigosos
     * (`javascript:`, `vbscript:`, `data:` exceto imagens quando permitido).
     * Retorna `#` para URLs inseguras e `''` para vazias. O escape de HTML fica a
     * cargo do Blade (`{{ }}`) — esta função cuida apenas do ESQUEMA. Para CSS,
     * componha com `cssUrl` (o contexto `url(...)` tem terminadores próprios).
     *
     * @param  bool  $allowDataImage  permite `data:image/...` (usado em <img src>).
     */
    public static function safeUrl(string $url, bool $allowDataImage = false): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F\s]/', '', $url) ?? $url;
        if ($clean === '') {
            return '';
        }
        $lower = strtolower($clean);

        if (str_starts_with($lower, 'data:')) {
            return ($allowDataImage && str_starts_with($lower, 'data:image/')) ? $clean : '#';
        }

        // Se há um esquema explícito (algo antes de ':'), só permite os seguros.
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $lower, $m)) {
            if (! in_array($m[1], ['http', 'https', 'mailto', 'tel'], true)) {
                return '#';
            }
        }

        return $clean;
    }

    // ─── Paginação: cabeçalho/rodapé (@page margin boxes) ─────

    /**
     * Escapa uma string para uso em `content:` de CSS.
     * Envolve em aspas duplas e escapa `"`, `\` e quebras de linha.
     */
    public static function cssString(string $s): string
    {
        $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $s);

        return '"' . $escaped . '"';
    }

    /**
     * Converte um template de cabeçalho/rodapé (com tokens {page} {pages}
     * {title} {date}) numa expressão CSS válida para `content:`.
     *
     * {page}/{pages} viram counter(page)/counter(pages) — contagem real do
     * Chrome. {title}/{date} são substituídos pelos valores literais (já que
     * o Chrome não suporta string-set()/string() para cabeçalhos dinâmicos).
     * Retorna `""` (string vazia) quando o template é vazio.
     */
    public static function furnitureContent(string $tpl, string $title, string $date): string
    {
        if ($tpl === '') {
            return '""';
        }

        // Resolve tokens literais antes de fatiar pelos counters.
        $tpl = str_replace(['{title}', '{date}'], [$title, $date], $tpl);

        $parts = preg_split(
            '/(\{page\}|\{pages\})/',
            $tpl,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: [];

        $out = [];
        foreach ($parts as $p) {
            if ($p === '{page}') {
                $out[] = 'counter(page)';
            } elseif ($p === '{pages}') {
                $out[] = 'counter(pages)';
            } else {
                $out[] = self::cssString($p);
            }
        }

        return $out === [] ? '""' : implode(' ', $out);
    }

    /**
     * Monta o bloco de @page margin boxes (3 zonas topo + 3 zonas base) a
     * partir das configurações de header/footer da DSL. Retorna o CSS interno
     * do `@page { ... }` (sem o seletor).
     *
     * @param  array<string,mixed>|null  $header
     * @param  array<string,mixed>|null  $footer
     */
    public static function pageMarginBoxes(?array $header, ?array $footer, string $title, string $date, string $defaultColor): string
    {
        $zones = [
            ['box' => '@top-left',      'cfg' => $header, 'key' => 'left'],
            ['box' => '@top-center',    'cfg' => $header, 'key' => 'center'],
            ['box' => '@top-right',     'cfg' => $header, 'key' => 'right'],
            ['box' => '@bottom-left',   'cfg' => $footer, 'key' => 'left'],
            ['box' => '@bottom-center', 'cfg' => $footer, 'key' => 'center'],
            ['box' => '@bottom-right',  'cfg' => $footer, 'key' => 'right'],
        ];

        $css = '';
        foreach ($zones as $z) {
            $cfg = $z['cfg'];
            if (! is_array($cfg)) {
                continue;
            }
            $text = $cfg[$z['key']] ?? '';
            if (! is_string($text) || $text === '') {
                continue;
            }
            $content = self::furnitureContent($text, $title, $date);
            $fontSize = (int) ($cfg['fontSize'] ?? 10);
            $color = is_string($cfg['color'] ?? null) && $cfg['color'] !== '' ? $cfg['color'] : $defaultColor;
            $color = CssSanitizer::color($color, CssSanitizer::color($defaultColor));
            $css .= "{$z['box']} { content: {$content}; font-size: {$fontSize}px; color: {$color}; }\n";
        }

        return $css;
    }

    // ─── Layouts de Página NOMEADOS por seção (P8/A2) ─────────
    // Cada Seção do v3 vira uma `@page` NOMEADA própria (espelha core/pageLayout
    // no TS). O Chromium aplica margin-boxes distintos por named page (spike 2026-06)
    // → header/footer por seção + visibilidade (showOn) na 1ª/última página. A
    // `@page nome:first` SOBREPÕE `@page nome` (specificity B) — provado no Chrome.

    /** Nome CSS estável da named page de uma seção (igual no @page e no `page:`). */
    public static function pageName(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $id);

        return 'pdfbsec' . (($clean ?? '') !== '' ? $clean : '0');
    }

    /**
     * PageSetup EFETIVO de uma seção: mescla os campos do Layout (layoutRef) SOBRE o
     * pageSetup (layout vence nos campos que define). Espelha core/pageLayout.resolvePageSetup.
     * paperSize/orientation/defaultFontFamily/pageMode vêm sempre do pageSetup.
     *
     * @param  array<string,mixed>  $pageSetup
     * @param  array<string,array<string,mixed>>  $layoutMap  id → PageLayout
     * @return array<string,mixed>
     */
    public static function resolvePageSetup(array $pageSetup, ?string $layoutRef, array $layoutMap): array
    {
        if ($layoutRef === null || $layoutRef === '' || ! isset($layoutMap[$layoutRef]) || ! is_array($layoutMap[$layoutRef])) {
            return $pageSetup;
        }
        $lay = $layoutMap[$layoutRef];
        $out = $pageSetup;
        foreach (['margins', 'header', 'footer', 'pageBackground', 'contentBackground', 'contentWidth'] as $k) {
            if (array_key_exists($k, $lay) && $lay[$k] !== null) {
                $out[$k] = $lay[$k];
            }
        }

        return $out;
    }

    /** Escopo efetivo da mobília: showOn (novo) com fallback no legado showOnFirstPage. */
    private static function furnitureScope(?array $cfg): string
    {
        if (! is_array($cfg)) {
            return 'all';
        }
        if (isset($cfg['showOn']) && is_string($cfg['showOn'])) {
            return $cfg['showOn'];
        }
        if (($cfg['showOnFirstPage'] ?? true) === false) {
            return 'except-first';
        }

        return 'all';
    }

    /**
     * Em quais páginas a mobília aparece, dado o escopo e a posição da seção.
     * `onNormal` = páginas "comuns" da seção; `onFirst` = a 1ª página do DOCUMENTO
     * (só existe na 1ª seção). "última" é aproximada como a ÚLTIMA SEÇÃO (CSS @page
     * não tem :last); doc de seção única degrada last/first-and-last para "all".
     *
     * @return array{onNormal: bool, onFirst: bool}
     */
    private static function furnitureFlags(string $scope, bool $isFirst, bool $isLast): array
    {
        switch ($scope) {
            case 'except-first':   return ['onNormal' => true,    'onFirst' => false];
            case 'first-only':     return ['onNormal' => false,   'onFirst' => true];
            case 'last-only':      return ['onNormal' => $isLast, 'onFirst' => ($isFirst && $isLast)];
            case 'first-and-last': return ['onNormal' => $isLast, 'onFirst' => $isFirst];
            case 'all':
            default:               return ['onNormal' => true,    'onFirst' => true];
        }
    }

    private static function oneBox(string $box, string $text, array $cfg, string $title, string $date, string $defaultColor): string
    {
        $content = self::furnitureContent($text, $title, $date);
        $fontSize = (int) ($cfg['fontSize'] ?? 10);
        $color = is_string($cfg['color'] ?? null) && $cfg['color'] !== '' ? $cfg['color'] : $defaultColor;
        $color = CssSanitizer::color($color, CssSanitizer::color($defaultColor));

        return "{$box} { content: {$content}; font-size: {$fontSize}px; color: {$color}; }\n";
    }

    /**
     * Zonas de margin-box de UMA seção: devolve [normal, first] onde `normal` vai no
     * `@page nome { }` (todas as páginas da seção) e `first` no `@page nome:first { }`
     * (sobrepõe a 1ª página do documento — só relevante na 1ª seção).
     *
     * @return array{0: string, 1: string}
     */
    private static function furnitureZones(?array $header, ?array $footer, bool $isFirst, bool $isLast, string $title, string $date, string $defaultColor): array
    {
        $zones = [
            ['box' => '@top-left',      'cfg' => $header, 'key' => 'left',   'part' => 'h'],
            ['box' => '@top-center',    'cfg' => $header, 'key' => 'center', 'part' => 'h'],
            ['box' => '@top-right',     'cfg' => $header, 'key' => 'right',  'part' => 'h'],
            ['box' => '@bottom-left',   'cfg' => $footer, 'key' => 'left',   'part' => 'f'],
            ['box' => '@bottom-center', 'cfg' => $footer, 'key' => 'center', 'part' => 'f'],
            ['box' => '@bottom-right',  'cfg' => $footer, 'key' => 'right',  'part' => 'f'],
        ];
        $hs = self::furnitureFlags(self::furnitureScope($header), $isFirst, $isLast);
        $fs = self::furnitureFlags(self::furnitureScope($footer), $isFirst, $isLast);

        $normal = '';
        $first = '';
        foreach ($zones as $z) {
            $cfg = $z['cfg'];
            if (! is_array($cfg)) {
                continue;
            }
            $text = $cfg[$z['key']] ?? '';
            if (! is_string($text) || $text === '') {
                continue;
            }
            $flags = $z['part'] === 'h' ? $hs : $fs;
            $boxCss = self::oneBox($z['box'], $text, $cfg, $title, $date, $defaultColor);
            if ($flags['onNormal']) {
                $normal .= '      ' . $boxCss;
            }
            // :first só é emitida na 1ª seção (única que contém a 1ª página do doc)
            // e só quando difere das páginas comuns.
            if ($isFirst && $flags['onFirst'] !== $flags['onNormal']) {
                $first .= $flags['onFirst']
                    ? '      ' . $boxCss
                    : "      {$z['box']} { content: none; }\n";
            }
        }

        return [$normal, $first];
    }

    /**
     * CSS de todas as `@page` NOMEADAS (uma por seção) do render v3 paginado: margin
     * (se o layout sobrescreve), background e os margin-boxes de header/footer com as
     * regras de `showOn`. O corpo (document-body) marca cada seção com `page: <nome>`.
     *
     * @param  array<int,array<string,mixed>>  $sections
     * @param  array<int,array<string,mixed>>|null  $pageLayouts
     */
    public static function namedPageCss(array $sections, ?array $pageLayouts, string $title, string $date, string $defaultColor): string
    {
        $map = [];
        foreach ($pageLayouts ?? [] as $pl) {
            if (is_array($pl) && isset($pl['id'])) {
                $map[$pl['id']] = $pl;
            }
        }
        $sections = array_values($sections);
        $n = count($sections);
        $out = '';
        foreach ($sections as $i => $sec) {
            if (! is_array($sec)) {
                continue;
            }
            $eff = self::resolvePageSetup(
                is_array($sec['pageSetup'] ?? null) ? $sec['pageSetup'] : [],
                is_string($sec['layoutRef'] ?? null) ? $sec['layoutRef'] : null,
                $map
            );
            $name = self::pageName((string) ($sec['id'] ?? (string) $i));
            $isFirst = $i === 0;
            $isLast = $i === $n - 1;
            $header = is_array($eff['header'] ?? null) ? $eff['header'] : null;
            $footer = is_array($eff['footer'] ?? null) ? $eff['footer'] : null;

            $decl = '';
            if (is_array($eff['margins'] ?? null)) {
                $mm = $eff['margins'];
                $decl .= sprintf(
                    "      margin: %smm %smm %smm %smm;\n",
                    CssSanitizer::number($mm['top'] ?? 20, '20'),
                    CssSanitizer::number($mm['right'] ?? 20, '20'),
                    CssSanitizer::number($mm['bottom'] ?? 20, '20'),
                    CssSanitizer::number($mm['left'] ?? 20, '20'),
                );
            }
            if (is_string($eff['pageBackground'] ?? null) && $eff['pageBackground'] !== '') {
                $decl .= '      background: ' . CssSanitizer::color($eff['pageBackground'], '#ffffff') . ";\n";
            }

            [$normal, $first] = self::furnitureZones($header, $footer, $isFirst, $isLast, $title, $date, $defaultColor);

            $body = $decl . $normal;
            if (trim($body) !== '') {
                $out .= "    @page {$name} {\n{$body}    }\n";
            }
            if ($isFirst && trim($first) !== '') {
                $out .= "    @page {$name}:first {\n{$first}    }\n";
            }
        }

        return $out;
    }
}
