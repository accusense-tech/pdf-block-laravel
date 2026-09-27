<?php

declare(strict_types=1);

// Teste standalone do FlowRender::collectAnchorTargets (alvos de âncora de clique).
// Espelha core/anchor (TS): coleta ids referenciados por href `#<id>` (mark link
// do texto ou url de botão), ignora âncoras de heading (#pdfb-h-...) e refs
// quebradas, e só retorna ids que existem como bloco.
//
// docker run --rm -v $PWD:/app -w /app php:8.3-cli php packages/laravel/tests/anchor_targets_check.php

require __DIR__ . '/../src/FlowRender.php';

use PdfBlock\Laravel\FlowRender as F;

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

// Helpers de construção do JSON TipTap.
$linkText = fn (string $t, string $href) => ['type' => 'text', 'text' => $t, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]];
$para = fn (array $inlines) => ['type' => 'paragraph', 'content' => $inlines];
$textBlock = fn (string $id, array $paras) => ['id' => $id, 'type' => 'text', 'content' => ['type' => 'doc', 'content' => $paras]];

$sections = [[
    'id' => 'sec', 'type' => 'section', 'flow' => [
        // alvos válidos
        $textBlock('alvo-texto', [$para([['type' => 'text', 'text' => 'destino']])]),
        // bloco de origem com 4 links: válido, quebrado, externo, âncora de heading
        $textBlock('origem', [$para([
            $linkText('ir', '#alvo-texto'),
            $linkText('quebrado', '#nao-existe'),
            $linkText('externo', 'https://ex.com'),
            $linkText('xref', '#pdfb-h-abc-0'),
        ])]),
        // botão apontando para uma âncora
        ['id' => 'alvo-btn', 'type' => 'image', 'src' => 'x'],
        ['id' => 'btn', 'type' => 'button', 'url' => '#alvo-btn'],
        // dentro de group: alvo aninhado + origem aninhada
        ['id' => 'grp', 'type' => 'group', 'flow' => [
            ['id' => 'alvo-aninhado', 'type' => 'image', 'src' => 'y'],
            $textBlock('origem2', [$para([$linkText('ver', '#alvo-aninhado')])]),
        ]],
    ],
]];

$targets = F::collectAnchorTargets($sections);
sort($targets);
check(
    'coleta alvos existentes (texto/botão/aninhado); ignora externo, heading e quebrado',
    $targets,
    ['alvo-aninhado', 'alvo-btn', 'alvo-texto'],
);

// Botão com url de variável ({{...}}) ou vazia → não vira âncora.
$noAnchor = [['id' => 's', 'type' => 'section', 'flow' => [
    ['id' => 'b', 'type' => 'button', 'url' => 'https://x/{{ping}}'],
    $textBlock('t', [$para([['type' => 'text', 'text' => 'sem link']])]),
]]];
check('sem âncoras → vazio', F::collectAnchorTargets($noAnchor), []);

// columnSet: alvo dentro de uma coluna.
$colset = [['id' => 's', 'type' => 'section', 'flow' => [
    ['id' => 'cs', 'type' => 'columnSet', 'columns' => [
        ['id' => 'c0', 'width' => 100, 'flow' => [
            ['id' => 'alvo-col', 'type' => 'image', 'src' => 'z'],
            $textBlock('o', [$para([$linkText('x', '#alvo-col')])]),
        ]],
    ]],
]]];
check('alvo dentro de columnSet', F::collectAnchorTargets($colset), ['alvo-col']);

echo $failures === 0 ? "\nTODOS OK\n" : "\n$failures FALHA(S)\n";
exit($failures === 0 ? 0 : 1);
