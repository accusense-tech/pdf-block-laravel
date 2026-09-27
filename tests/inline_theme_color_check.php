<?php

declare(strict_types=1);

// Teste standalone do InlineThemeColorResolver (cor de texto inline re-tematizável).
// Garante paridade com reprojectInlineThemeColors (packages/react/src/core/themes.ts):
// marca textStyle com `themeColor` → `color` re-escrito pela paleta ativa; marca sem
// themeColor fica intacta; header/footer também re-vestem; nome órfão não muda nada.
// Rodar via docker: php packages/laravel/tests/inline_theme_color_check.php

require __DIR__ . '/../src/Data/InlineThemeColorResolver.php';

use PdfBlock\Laravel\Data\InlineThemeColorResolver;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (! $cond) { $failures++; }
}

// Fábrica de um bloco text com um trecho tematizado + um trecho de cor fixa.
function textBlock(string $id): array
{
    return [
        'id' => $id, 'type' => 'text',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Tema', 'marks' => [
                    ['type' => 'textStyle', 'attrs' => ['color' => '#OLDOLD', 'themeColor' => 'accent']],
                ]],
                ['type' => 'text', 'text' => 'Fixo', 'marks' => [
                    ['type' => 'textStyle', 'attrs' => ['color' => '#123456']],
                ]],
                ['type' => 'text', 'text' => 'Órfão', 'marks' => [
                    ['type' => 'textStyle', 'attrs' => ['color' => '#999999', 'themeColor' => 'inexistente']],
                ]],
            ]],
        ]],
    ];
}

$doc = [
    'theme' => ['colors' => ['accent' => '#37F3C8', 'surface' => '#10131A']],
    'sections' => [[
        'id' => 's1', 'type' => 'section',
        'flow' => [textBlock('t1')],
    ]],
    'header' => ['id' => 'h', 'type' => 'header', 'enabled' => true, 'flow' => [textBlock('th')]],
];

$out = InlineThemeColorResolver::resolve($doc);

$marksOf = fn(array $block) => $block['content']['content'][0]['content'];

// Seção: trecho tematizado re-veste; trecho fixo intacto; órfão intacto.
$secMarks = $marksOf($out['sections'][0]['flow'][0]);
check('inline themeColor re-veste (accent → hex ativo)', $secMarks[0]['marks'][0]['attrs']['color'] === '#37F3C8');
check('inline themeColor preserva o nome', $secMarks[0]['marks'][0]['attrs']['themeColor'] === 'accent');
check('cor fixa (sem themeColor) fica intacta', $secMarks[1]['marks'][0]['attrs']['color'] === '#123456');
check('themeColor órfão não altera a cor', $secMarks[2]['marks'][0]['attrs']['color'] === '#999999');

// Header também re-veste (percorre em qualquer profundidade).
$hdrMarks = $marksOf($out['header']['flow'][0]);
check('header inline themeColor re-veste', $hdrMarks[0]['marks'][0]['attrs']['color'] === '#37F3C8');

// Trocar a paleta ativa muda a cor resolvida (re-vestimento de fato).
$doc2 = $doc;
$doc2['theme']['colors']['accent'] = '#FF0000';
$out2 = InlineThemeColorResolver::resolve($doc2);
check('trocar paleta re-veste a cor inline', $marksOf($out2['sections'][0]['flow'][0])[0]['marks'][0]['attrs']['color'] === '#FF0000');

// Sem theme → documento inalterado.
$noTheme = ['sections' => [['id' => 's', 'type' => 'section', 'flow' => [textBlock('x')]]]];
check('sem theme → inalterado', InlineThemeColorResolver::resolve($noTheme) === $noTheme);

echo "\n" . ($failures === 0 ? "TODOS OS TESTES PASSARAM\n" : "$failures FALHA(S)\n");
exit($failures === 0 ? 0 : 1);
