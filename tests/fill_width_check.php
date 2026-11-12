<?php

declare(strict_types=1);

// Grupo esticado na largura (`fillWidth`). Num pai em LINHA o `align-self:stretch`
// esticava na ALTURA e vencia o `align-items:center` do pai: o cartão ao lado de
// uma imagem saía da altura dela, com o texto no topo (bateria do MCP de 22/out).
// Gêmeo de `fillWidthCss` em react/src/core/fillHeight.ts (fillHeight.test.ts).

require __DIR__ . '/../vendor/autoload.php';

use PdfBlock\Laravel\FlowRender;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (! $cond) { $failures++; }
}

check('em linha: cresce na largura sem esticar na altura', FlowRender::fillWidthCss(true) === 'flex:1 1 0%;min-width:0;');
check('empilhado: continua esticando na largura', FlowRender::fillWidthCss(false) === 'flex:1 1 0%;min-width:0;align-self:stretch;');

$group = (string) file_get_contents(__DIR__ . '/../resources/views/v3/group.blade.php');
$columnset = (string) file_get_contents(__DIR__ . '/../resources/views/v3/columnset.blade.php');
// Todo fluxo que um container hospeda diz o eixo: sem isso o grupo herdaria o do avô.
$flowIncludes = array_filter(explode("\n", $group), fn ($l) => preg_match("/v3\\.(column|structure)-flow'/", $l) === 1);
check('o grupo passa o eixo a todo fluxo que inclui',
    count($flowIncludes) > 0 && array_filter($flowIncludes, fn ($l) => ! str_contains($l, "'inRow' =>")) === []);
check('a coluna do columnSet zera o eixo', str_contains($columnset, "'inRow' => false"));

// Item da linha: a imagem de largura fixa não encolhe ao lado de texto longo
// (o logo de 56px saía com 18px). Gêmeo de `rowItemCss` (rowLayout.test.ts).
check('imagem fixa numa linha não encolhe', FlowRender::rowItemCss(['type' => 'image', 'width' => 56], true) === 'flex-shrink:0;max-width:100%;');
check('fora da linha nada muda', FlowRender::rowItemCss(['type' => 'image', 'width' => 56], false) === '');
check('largura automática nada muda', FlowRender::rowItemCss(['type' => 'image', 'width' => 'auto'], true) === '');
check('texto nada muda', FlowRender::rowItemCss(['type' => 'text'], true) === '');
$block = (string) file_get_contents(__DIR__ . '/../resources/views/block.blade.php');
check('o dispatcher aplica o CSS do item da linha', str_contains($block, 'FlowRender::rowItemCss($block, $inRow ?? false)'));

exit($failures > 0 ? 1 : 0);
