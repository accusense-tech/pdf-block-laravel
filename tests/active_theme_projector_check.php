<?php

declare(strict_types=1);

// Teste standalone do ActiveThemeProjector (themes[]/activeThemeId → theme.colors).
// Espelha projectActiveTheme (packages/react/src/core/themes.ts): sem esta projeção,
// um documento que declara só `themes` (o caso de JSON gerado fora do editor) deixa
// todo {{token:colors.*}} resolver para '' — o tema "existe" e nada é aplicado.
// Rodar via docker: php packages/laravel/tests/active_theme_projector_check.php

require __DIR__.'/../src/Data/ActiveThemeProjector.php';
require __DIR__.'/../src/Data/ThemeResolver.php';

use PdfBlock\Laravel\Data\ActiveThemeProjector;
use PdfBlock\Laravel\Data\ThemeResolver;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ').$name."\n";
    if (! $cond) {
        $failures++;
    }
}

$base = [
    'version' => '3.0.0',
    'themes' => [
        ['id' => 'azul', 'name' => 'Azul', 'colors' => ['accent' => '#2563eb', 'ink' => '#0f172a']],
        ['id' => 'verde', 'name' => 'Verde', 'colors' => ['accent' => '#16a34a']],
    ],
    'activeThemeId' => 'verde',
    'theme' => ['spacing' => ['md' => 16]],
    'sections' => [[
        'flow' => [[
            'type' => 'text',
            'fontColor' => '{{token:colors.accent}}',
            'styles' => ['padding' => ['top' => '{{token:spacing.md}}']],
        ]],
    ]],
];

// 1. Sem projeção o token morre (comportamento anterior — documenta o porquê do fix).
$semProjecao = ThemeResolver::resolve($base);
check('sem projeção o token de cor resolve vazio', $semProjecao['sections'][0]['flow'][0]['fontColor'] === '');

// 2. Com projeção resolve pela paleta do tema ATIVO (não do primeiro).
$comProjecao = ThemeResolver::resolve(ActiveThemeProjector::project($base));
check('tema ativo (verde) aplicado', $comProjecao['sections'][0]['flow'][0]['fontColor'] === '#16a34a');
check('spacing do theme preservado', $comProjecao['sections'][0]['flow'][0]['styles']['padding']['top'] === 16);

// 3. activeThemeId ausente/inválido cai no primeiro tema.
$semAtivo = $base;
unset($semAtivo['activeThemeId']);
$r = ThemeResolver::resolve(ActiveThemeProjector::project($semAtivo));
check('sem activeThemeId cai no 1º tema', $r['sections'][0]['flow'][0]['fontColor'] === '#2563eb');

$idInvalido = $base;
$idInvalido['activeThemeId'] = 'inexistente';
$r = ThemeResolver::resolve(ActiveThemeProjector::project($idInvalido));
check('activeThemeId inválido cai no 1º tema', $r['sections'][0]['flow'][0]['fontColor'] === '#2563eb');

// 4. theme.colors DEFASADO é sobrescrito pela paleta ativa (invariante do editor).
$defasado = $base;
$defasado['theme']['colors'] = ['accent' => '#ff0000'];
$r = ActiveThemeProjector::project($defasado);
check('theme.colors defasado é substituído', $r['theme']['colors']['accent'] === '#16a34a');

// 5. Gradientes do tema ativo também são projetados; some quando o ativo não tem.
$comGrad = $base;
$comGrad['themes'][1]['gradients'] = ['hero' => ['type' => 'gradient', 'gradientType' => 'linear', 'angle' => 90, 'stops' => []]];
$comGrad['theme']['gradients'] = ['antigo' => ['type' => 'gradient']];
$r = ActiveThemeProjector::project($comGrad);
check('gradientes do ativo projetados', isset($r['theme']['gradients']['hero']) && ! isset($r['theme']['gradients']['antigo']));

$semGrad = ActiveThemeProjector::project($base);
check('sem gradientes no ativo, theme.gradients sai', ! isset($semGrad['theme']['gradients']));

// 6. Documento SEM themes[] passa intacto (v2/e-mail e docs sem temas nomeados).
$semThemes = ['theme' => ['colors' => ['accent' => '#111']]];
check('documento sem themes[] não é tocado', ActiveThemeProjector::project($semThemes) === $semThemes);

echo $failures === 0 ? "\nTODOS OK\n" : "\n{$failures} FALHA(S)\n";
exit($failures === 0 ? 0 : 1);
