<?php

declare(strict_types=1);

// PARIDADE DE PIPELINE entre o PDF e o e-mail (auditoria ago/2026).
//
// Os dois renderers preparam o documento antes de renderizar: projetam o tema
// ativo, resolvem `{{token:...}}` e re-vestem a cor de texto INLINE. O e-mail
// pulava o último passo — um texto cuja cor veio do tema saía com o hex ANTIGO,
// então trocar o tema ativo mudava o PDF e não mudava o e-mail.
//
// Este teste compara os PASSOS, não a saída: o que precisa ser igual é a
// preparação. Cada passo novo num renderer que não entre no outro reaparece
// aqui.

require __DIR__ . '/../src/Data/ActiveThemeProjector.php';
require __DIR__ . '/../src/Data/ThemeResolver.php';
require __DIR__ . '/../src/Data/InlineThemeColorResolver.php';

use PdfBlock\Laravel\Data\ActiveThemeProjector;
use PdfBlock\Laravel\Data\InlineThemeColorResolver;
use PdfBlock\Laravel\Data\ThemeResolver;

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . ($cond ? '' : " — $detail") . "\n";
    if (! $cond) { $failures++; }
}

/** Os passos de preparação que os DOIS renderers precisam aplicar, na ordem. */
const PASSOS = ['ActiveThemeProjector::project', 'ThemeResolver::resolve', 'InlineThemeColorResolver::resolve'];

function pipelineDe(string $arquivo): array
{
    $src = file_get_contents(__DIR__ . '/../src/' . $arquivo);
    $achados = [];
    foreach (PASSOS as $passo) {
        [$classe, $metodo] = explode('::', $passo);
        if (preg_match('/' . preg_quote($classe, '/') . '::' . $metodo . '\s*\(/', $src)) {
            $achados[] = $passo;
        }
    }
    return $achados;
}

$pdf   = pipelineDe('PdfBlockRenderer.php');
$email = pipelineDe('Email/EmailRenderer.php');

check('o PDF aplica os três passos', $pdf === PASSOS, implode(', ', $pdf));
check('o e-mail aplica os MESMOS passos', $email === $pdf, 'e-mail: ' . implode(', ', $email));

// ── E o passo que faltava faz o que promete ──
$doc = [
    'theme' => ['colors' => ['primaria' => '#ff0000']],
    'blocks' => [[
        'id' => 's', 'type' => 'stripe', 'children' => [[
            'id' => 'st', 'type' => 'structure', 'columns' => [[
                'id' => 'c', 'width' => 100, 'children' => [[
                    'id' => 't', 'type' => 'text',
                    'content' => ['type' => 'doc', 'content' => [[
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text', 'text' => 'oi',
                            // hex DEFASADO + o nome da cor do tema: é o caso real
                            // (o tema ativo mudou depois que o texto foi escrito).
                            'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#000000', 'themeColor' => 'primaria']]],
                        ]],
                    ]]],
                ]],
            ]],
        ]],
    ]],
];

$out = InlineThemeColorResolver::resolve(ActiveThemeProjector::project(ThemeResolver::resolve($doc)));
$cor = $out['blocks'][0]['children'][0]['columns'][0]['children'][0]['content']['content'][0]['content'][0]['marks'][0]['attrs']['color'] ?? null;
check('cor inline re-vestida pela paleta ativa', $cor === '#ff0000', var_export($cor, true));

exit($failures > 0 ? 1 : 0);
