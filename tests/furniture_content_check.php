<?php

declare(strict_types=1);

// Teste standalone dos helpers de paginação do StyleHelpers
// (cabeçalho/rodapé → @page margin boxes, tokens {page}/{pages}/{title}/{date}).
// docker run --rm -v $PWD:/app -w /app php:8.3-cli php packages/laravel/tests/furniture_content_check.php

require __DIR__ . '/../src/CssSanitizer.php';
require __DIR__ . '/../src/StyleHelpers.php';

use PdfBlock\Laravel\StyleHelpers as S;

$failures = 0;
function check(string $name, mixed $got, mixed $expected): void
{
    global $failures;
    if ($got === $expected) {
        echo "ok: $name\n";
    } else {
        $failures++;
        echo "FAIL: $name\n  esperado: " . json_encode($expected) . "\n  obtido:   " . json_encode($got) . "\n";
    }
}

// ── cssString: escape para content: ──
check('cssString simples', S::cssString('abc'), '"abc"');
check('cssString com aspas', S::cssString('di"z'), '"di\\"z"');
check('cssString com barra', S::cssString('a\\b'), '"a\\\\b"');
check('cssString remove quebras', S::cssString("a\nb"), '"ab"');

// ── furnitureContent: tokens → counters / strings ──
check('só texto', S::furnitureContent('Confidencial', 'T', 'D'), '"Confidencial"');
check('vazio', S::furnitureContent('', 'T', 'D'), '""');
check('counter page', S::furnitureContent('{page}', 'T', 'D'), 'counter(page)');
check('counter pages', S::furnitureContent('{pages}', 'T', 'D'), 'counter(pages)');
check(
    'página X de Y',
    S::furnitureContent('Página {page} de {pages}', 'T', 'D'),
    '"Página " counter(page) " de " counter(pages)'
);
check('title token', S::furnitureContent('{title}', 'Relatório', 'D'), '"Relatório"');
check('date token', S::furnitureContent('{date}', 'T', '01/02/2026'), '"01/02/2026"');
check(
    'misto title + counters',
    S::furnitureContent('{title} — {page}/{pages}', 'Doc', 'D'),
    '"Doc — " counter(page) "/" counter(pages)'
);

// ── pageMarginBoxes: monta zonas a partir de header/footer ──
$boxes = S::pageMarginBoxes(
    ['left' => '{title}', 'right' => '{date}', 'fontSize' => 9],
    ['center' => 'Página {page} de {pages}', 'fontSize' => 10, 'color' => '#555'],
    'Meu Doc',
    '01/02/2026',
    '#333333'
);
check('box top-left presente', str_contains($boxes, '@top-left { content: "Meu Doc"; font-size: 9px; color: #333333; }'), true);
check('box top-right data', str_contains($boxes, '@top-right { content: "01/02/2026"; font-size: 9px; color: #333333; }'), true);
check('box bottom-center counters', str_contains($boxes, '@bottom-center { content: "Página " counter(page) " de " counter(pages); font-size: 10px; color: #555; }'), true);
check('zonas vazias omitidas (top-center)', str_contains($boxes, '@top-center'), false);
check('zonas vazias omitidas (bottom-left)', str_contains($boxes, '@bottom-left'), false);

// header/footer nulos → vazio
check('sem furniture → vazio', S::pageMarginBoxes(null, null, 'T', 'D', '#000'), '');

echo $failures === 0 ? "\nTODOS OS TESTES PASSARAM\n" : "\n$failures TESTE(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
