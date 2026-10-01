<?php

declare(strict_types=1);

// Teste standalone do ThemeResolver (tokens de tema {{token:...}}).
// Garante paridade com resolveThemeTokens (packages/react/src/data/theme.ts):
// token puro preserva o tipo numérico; embutido interpola; órfão → ''.
// Rodar via docker: php packages/laravel/tests/theme_resolver_check.php

require __DIR__ . '/../src/Data/ThemeResolver.php';

use PdfBlock\Laravel\Data\ThemeResolver;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (! $cond) { $failures++; }
}

$doc = [
    'theme' => [
        'colors'  => ['accent' => '#37F3C8', 'surface' => '#10131A'],
        'spacing' => ['md' => 16, 'lg' => 24],
        'radius'  => ['card' => 16],
    ],
    'globalStyles' => ['pageBackground' => '{{token:colors.surface}}'],
    'blocks' => [
        [
            'id' => 's1', 'type' => 'stripe',
            'styles' => [
                'background' => ['type' => 'solid', 'color' => '{{token:colors.accent}}'],
                'padding' => ['top' => '{{token:spacing.md}}', 'left' => '{{token:spacing.lg}}'],
                'borderRadius' => ['topLeft' => '{{token:radius.card}}'],
            ],
            'children' => [
                ['id' => 't1', 'type' => 'text', 'fontColor' => 'mistura {{token:colors.accent}} aqui'],
            ],
        ],
    ],
];

$out = ThemeResolver::resolve($doc);

// Cor (string) resolvida.
check('color token resolve para hex', $out['blocks'][0]['styles']['background']['color'] === '#37F3C8');
check('globalStyles token resolve', $out['globalStyles']['pageBackground'] === '#10131A');

// Spacing/radius (token puro) preservam o tipo NUMÉRICO (essencial p/ CSS px).
check('spacing token puro vira int 16', $out['blocks'][0]['styles']['padding']['top'] === 16);
check('spacing token puro vira int 24', $out['blocks'][0]['styles']['padding']['left'] === 24);
check('radius token puro vira int 16', $out['blocks'][0]['styles']['borderRadius']['topLeft'] === 16);

// Token embutido numa string maior interpola (string).
check('token embutido interpola', $out['blocks'][0]['children'][0]['fontColor'] === 'mistura #37F3C8 aqui');

// A definição do tema é preservada intacta.
check('theme preservado', $out['theme']['colors']['accent'] === '#37F3C8');

// Token inexistente → '' (igual a binding inválido).
$orphan = ThemeResolver::resolve([
    'theme' => ['colors' => ['a' => '#fff']],
    'blocks' => [['id' => 'x', 'type' => 'text', 'fontColor' => '{{token:colors.naoexiste}}']],
]);
check('token órfão vira ""', $orphan['blocks'][0]['fontColor'] === '');

// Sem theme → documento inalterado.
$noTheme = ['blocks' => [['id' => 'x', 'type' => 'text', 'fontColor' => '{{token:colors.a}}']]];
check('sem theme → inalterado', ThemeResolver::resolve($noTheme) === $noTheme);

echo "\n" . ($failures === 0 ? "TODOS OS TESTES PASSARAM\n" : "$failures FALHA(S)\n");
exit($failures === 0 ? 0 : 1);
