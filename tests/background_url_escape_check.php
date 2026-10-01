<?php

declare(strict_types=1);

// URL da imagem de fundo no CSS (auditoria ago/2026, item 3.2).
//
// `StyleHelpers::backgroundToCSS` escapava a URL com `e()` — mas as blades
// emitem o atributo `style` com `{{ }}`, que JÁ escapa. Era escape duplo:
// `?w=100&h=50` chegava ao navegador como `?w=100&amp;h=50` e a imagem não
// carregava. Casa com o sintoma relatado em 10/ago ("as imagens não estão
// renderizando no PDF gerado pelo browserless") para todo fundo servido com
// query string — presigned S3, CDN com redimensionamento.
//
// A proteção que faz sentido aqui é de ESQUEMA (safeUrl), não de entidade HTML.

require __DIR__ . '/../src/StyleHelpers.php';

use PdfBlock\Laravel\StyleHelpers as S;

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . ($cond ? '' : " — $detail") . "\n";
    if (! $cond) { $failures++; }
}

function bgCss(string $url): string
{
    return S::backgroundToCSS(['type' => 'image', 'url' => $url]);
}

// ── O caso que quebrava em produção ──
$css = bgCss('https://cdn.example.com/i.png?w=100&h=50');
check('query string atravessa sem virar entidade', str_contains($css, '?w=100&h=50'), $css);
check('nenhum &amp; no CSS', ! str_contains($css, '&amp;'), $css);

// ── Aspas: também eram escapadas duas vezes ──
$css = bgCss("https://cdn.example.com/it's.png");
check('apóstrofo não vira &#039;', ! str_contains($css, '&#039;'), $css);

// ── data:image continua passando (thumbnails embutidas) ──
$css = bgCss('data:image/png;base64,iVBORw0KGgo=');
check('data:image/* é aceito', str_contains($css, 'data:image/png;base64,iVBORw0KGgo='), $css);

// ── Esquema perigoso continua barrado (é para isto que a sanitização serve) ──
$css = bgCss('javascript:alert(1)');
check('javascript: é neutralizado', ! str_contains($css, 'javascript:'), $css);

// ── Controle: o resto da declaração não mudou ──
$css = bgCss('https://x/y.png');
check('emite size/repeat/position junto', str_contains($css, 'background-size:cover;')
    && str_contains($css, 'background-repeat:no-repeat;')
    && str_contains($css, 'background-position:center center;'), $css);

// ── `)` não fecha o url() (auditoria R2, item D) ──
//
// `safeUrl` cuida do ESQUEMA; dentro de `url(` sem aspas faltava o contexto. Uma
// URL de imagem terminada em `);background:red;` reescrevia o estilo do documento
// — e num renderizador headless `url(https://…/?vazamento)` dispara a requisição
// a partir do servidor. `cssUrl` percent-encoda os terminadores.
$css = bgCss('https://x/y.png);background:red;color:blue;/*');
check('`)` não fecha o url()', ! str_contains($css, 'y.png);background:red'), $css);
check('`)` vira %29', str_contains($css, 'y.png%29'), $css);
check('aspas viram %27/%22', S::cssUrl('a\'b"c') === 'a%27b%22c', S::cssUrl('a\'b"c'));

// ── O GÊMEO DAS BLADES (auditoria R2, item A) ──
//
// `StyleHelpers::backgroundToCSS` não é o único lugar que monta um `url(` — o
// fundo do BANNER é montado à mão em duas blades, e a correção do escape duplo
// só chegou ao helper: por meses o banner com presigned S3 saiu sem imagem no
// PDF enquanto o teste acima passava. Estas duas checagens leem as blades: é a
// única forma de o gêmeo não divergir de novo em silêncio.
$blades = [
    __DIR__ . '/../resources/views/v3/group.blade.php',
    __DIR__ . '/../resources/views/structure.blade.php',
];
foreach ($blades as $blade) {
    $nome = basename($blade);
    $src = (string) file_get_contents($blade);
    // Toda montagem de `url(` na blade — o que estiver entre parênteses.
    preg_match_all('/background-image:url\(\'\s*\.\s*([^;]+?)\s*\.\s*\'\)/', $src, $m);
    check("$nome: monta o url( de forma reconhecível", count($m[1]) > 0, $src);
    foreach ($m[1] as $expr) {
        check("$nome: url( não usa e()", ! str_contains($expr, 'e('), $expr);
        check("$nome: url( passa por cssUrl+safeUrl",
            str_contains($expr, 'cssUrl') && str_contains($expr, 'safeUrl'), $expr);
    }
}

exit($failures > 0 ? 1 : 0);
