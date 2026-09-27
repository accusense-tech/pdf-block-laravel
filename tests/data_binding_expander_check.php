<?php

declare(strict_types=1);

// Teste standalone do DataBindingExpander + ItemScopeExpander (loop materializado).
// docker run --rm -v $PWD:/app -w /app php:8.3-cli php packages/laravel/tests/data_binding_expander_check.php

require __DIR__ . '/../src/Data/ValueFormatter.php';
require __DIR__ . '/../src/Data/BindingResolver.php';
require __DIR__ . '/../src/Data/ItemScopeExpander.php';
require __DIR__ . '/../src/Data/DataBindingExpander.php';

use PdfBlock\Laravel\Data\DataBindingExpander;

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    if ($ok) { echo "ok: $name\n"; }
    else { $failures++; echo "FAIL: $name $extra\n"; }
}

// Helpers de construção
function textWithVar(string $id, string $path): array
{
    return ['type' => 'text', 'id' => $id, 'content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['path' => $path, 'label' => $path]]]],
    ]]];
}
/** Bloco ligado a UM item da lista (o que o editor materializa). */
function itemScope(string $list, ?string $item, int $index): array
{
    return array_filter(['list' => $list, 'item' => $item, 'index' => $index], fn ($v) => $v !== null) + ['index' => $index];
}
function structure(string $id, array $children, ?array $scope = null): array
{
    $st = ['type' => 'structure', 'id' => $id, 'columns' => [['id' => $id . '-c', 'children' => $children]]];
    if ($scope) $st['itemScope'] = $scope;
    return $st;
}
function stripe(string $id, array $children): array
{
    return ['type' => 'stripe', 'id' => $id, 'children' => $children];
}

$data = ['noticias' => [
    ['__id' => 'n1', 'title' => 'AAA'],
    ['__id' => 'n2', 'title' => 'BBB'],
    ['__id' => 'n3', 'title' => 'CCC'],
]];

// ── 1. Série materializada: 1 bloco por item, cada um resolve o SEU item ──
$docSeries = ['blocks' => [
    stripe('s1', [
        structure('st1', [textWithVar('t1', 'title')], itemScope('noticias', 'n1', 0)),
        structure('st2', [textWithVar('t2', 'title')], itemScope('noticias', 'n2', 1)),
        structure('st3', [textWithVar('t3', 'title')], itemScope('noticias', 'n3', 2)),
    ]),
]];
$out = DataBindingExpander::expand($docSeries, $data);
$structs = $out['blocks'][0]['children'];
check('série: 3 blocos', count($structs) === 3, '(got ' . count($structs) . ')');
$json = json_encode($out);
check('série: resolve AAA/BBB/CCC', str_contains($json, 'AAA') && str_contains($json, 'BBB') && str_contains($json, 'CCC'));
check('série: remove itemScope da saída', ! str_contains($json, 'itemScope'));
check('série: sem nós variable remanescentes', ! str_contains($json, '"variable"'));

// ── 2. RECONCILIAÇÃO com dados de runtime: mais itens → clona o BASE ──
$docOne = ['blocks' => [stripe('s1', [
    structure('st1', [textWithVar('t1', 'title')], itemScope('noticias', 'n1', 0)),
])]];
$out2 = DataBindingExpander::expand($docOne, $data);
check('runtime+: 1 bloco vira 3 (clona o base)', count($out2['blocks'][0]['children']) === 3);
$j2 = json_encode($out2);
check('runtime+: os itens novos resolvem', str_contains($j2, 'BBB') && str_contains($j2, 'CCC'));

// ── 3. Menos itens que blocos: os blocos sobrando saem ──
$out3 = DataBindingExpander::expand($docSeries, ['noticias' => [['__id' => 'n2', 'title' => 'BBB']]]);
check('runtime-: sobra 1 bloco', count($out3['blocks'][0]['children']) === 1);
check('runtime-: o bloco é o do item n2', str_contains(json_encode($out3), 'BBB'));

// ── 4. Ids TOTALMENTE diferentes (dados novos do host) → casa por POSIÇÃO ──
$runtime = ['noticias' => [
    ['__id' => 'x1', 'title' => 'UM'],
    ['__id' => 'x2', 'title' => 'DOIS'],
]];
$out4 = DataBindingExpander::expand($docSeries, $runtime);
check('posição: 2 blocos (1 por registro)', count($out4['blocks'][0]['children']) === 2);
$j4 = json_encode($out4);
check('posição: resolve UM/DOIS', str_contains($j4, 'UM') && str_contains($j4, 'DOIS'));

// ── 5. Lista VAZIA: os blocos da série não saem no PDF ──
$out5 = DataBindingExpander::expand($docSeries, ['noticias' => []]);
check('lista vazia: nenhum bloco da série sai', count($out5['blocks'][0]['children']) === 0);

// ── 6. ESCOPO do item: globais visíveis + item.numero/item.total injetados ──
$docScope = ['blocks' => [
    stripe('s2', [
        structure('sc1', [
            textWithVar('t2', 'empresa'),      // global (não sombreada pelo item)
            textWithVar('t2b', 'item.numero'), // automática por item (1-based)
            textWithVar('t2c', 'item.total'),
        ], itemScope('noticias', 'n1', 0)),
    ]),
]];
$out6 = DataBindingExpander::expand($docScope, array_merge($data, ['empresa' => 'ACME']));
$j6 = json_encode($out6);
check('escopo do item: global visível', substr_count($j6, 'ACME') === 3);
check('escopo do item: item.total = 3', str_contains($j6, '{"type":"text","text":"3"}'));

// ── 7. Avulso (sem vínculo): resolve contra data global ──
$docPlain = ['blocks' => [stripe('s3', [structure('st3', [textWithVar('t3', 'title')])])]];
$out7 = DataBindingExpander::expand($docPlain, ['title' => 'GLOBAL']);
check('avulso: resolve data global', str_contains(json_encode($out7), 'GLOBAL'));

// ── Helpers v3 (Seções/fluxo) ──
function v3columnSet(string $id, array $colFlows, ?array $scope = null): array
{
    $cols = [];
    foreach ($colFlows as $i => $flow) {
        $cols[] = ['id' => $id . '-c' . $i, 'width' => 100 / max(count($colFlows), 1), 'flow' => $flow];
    }
    $cs = ['type' => 'columnSet', 'id' => $id, 'columns' => $cols];
    if ($scope) $cs['itemScope'] = $scope;
    return $cs;
}
function v3group(string $id, array $flow, ?array $scope = null): array
{
    $g = ['type' => 'group', 'id' => $id, 'flow' => $flow];
    if ($scope) $g['itemScope'] = $scope;
    return $g;
}
function v3doc(array $flow): array
{
    return ['version' => '3.0.0', 'sections' => [['id' => 'sec', 'type' => 'section', 'flow' => $flow]]];
}

// ── 8. v3: columnSet de lista (1 bloco no doc, 3 registros) ──
$v3Series = v3doc([
    v3columnSet('cs1', [[textWithVar('vt1', 'title')]], itemScope('noticias', 'n1', 0)),
]);
$o8 = DataBindingExpander::expand($v3Series, $data);
$flow8 = $o8['sections'][0]['flow'];
check('v3: 3 columnSets após reconciliar', count($flow8) === 3, '(got ' . count($flow8) . ')');
$j8 = json_encode($o8);
check('v3: resolve AAA/BBB/CCC', str_contains($j8, 'AAA') && str_contains($j8, 'BBB') && str_contains($j8, 'CCC'));
check('v3: ids dos clones são únicos', count(array_unique(array_column($flow8, 'id'))) === 3);
check('v3: sem itemScope na saída', ! str_contains($j8, 'itemScope'));

// ── 9. v3 FOLHA de lista: o próprio bloco (sem container) ──
$v3Leaf = v3doc([
    v3group('g1', [
        array_merge(textWithVar('vt2', 'title'), ['itemScope' => itemScope('noticias', 'n1', 0)]),
    ]),
]);
$o9 = DataBindingExpander::expand($v3Leaf, $data);
$g1flow = $o9['sections'][0]['flow'][0]['flow'];
check('v3 folha: 3 blocos', count($g1flow) === 3, '(got ' . count($g1flow) . ')');
check('v3 folha: resolve por item', str_contains(json_encode($o9), 'BBB'));

// ── 10. v3 avulso: folha solta no flow da seção resolve data global ──
$v3Plain = v3doc([textWithVar('vt3', 'title')]);
$o10 = DataBindingExpander::expand($v3Plain, ['title' => 'GLOBAL']);
check('v3 avulso: resolve data global', str_contains(json_encode($o10), 'GLOBAL'));

// ── 11. Cabeçalho/rodapé document-level (v3): o `flow` da mobília resolve variáveis ──
$v3Furniture = array_merge(v3doc([textWithVar('body', 'title')]), [
    'header' => ['enabled' => true, 'flow' => [textWithVar('h', 'title')]],
    'footer' => ['enabled' => true, 'flow' => [textWithVar('f', 'title')]],
]);
$o11 = DataBindingExpander::expand($v3Furniture, ['title' => 'FURNI']);
$jh = json_encode($o11['header']);
$jf = json_encode($o11['footer']);
check('header: resolve variável (FURNI)', str_contains($jh, 'FURNI'), "($jh)");
check('header: sem nós variable remanescentes', ! str_contains($jh, '"variable"'), "($jh)");
check('footer: resolve variável (FURNI)', str_contains($jf, 'FURNI'), "($jf)");
check('footer: sem nós variable remanescentes', ! str_contains($jf, '"variable"'), "($jf)");

// ── 12. ANINHADO: série DENTRO de um bloco de lista (lista do item) ──
$nested = v3doc([
    v3group('gout', [
        v3group('gin', [textWithVar('vin', 'nome')], itemScope('membros', 'm1', 0)),
    ], itemScope('times', 't1', 0)),
]);
$nestedData = ['times' => [
    ['__id' => 't1', 'titulo' => 'Alfa', 'membros' => [['__id' => 'm1', 'nome' => 'Ana'], ['__id' => 'm2', 'nome' => 'Bia']]],
    ['__id' => 't2', 'titulo' => 'Beta', 'membros' => [['__id' => 'm3', 'nome' => 'Caio']]],
]];
$o12 = DataBindingExpander::expand($nested, $nestedData);
$j12 = json_encode($o12);
check('aninhado: 2 times no topo', count($o12['sections'][0]['flow']) === 2);
check('aninhado: membros por time', str_contains($j12, 'Ana') && str_contains($j12, 'Bia') && str_contains($j12, 'Caio'));

// ── 13. Edição POR BLOCO sobrevive: o bloco do item 2 tem texto próprio ──
$docEdited = v3doc([
    v3group('ga', [textWithVar('ta', 'title')], itemScope('noticias', 'n1', 0)),
    v3group('gb', [['type' => 'text', 'id' => 'tb', 'content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'SO-NESTE']]],
    ]]]], itemScope('noticias', 'n2', 1)),
    v3group('gc', [textWithVar('tc', 'title')], itemScope('noticias', 'n3', 2)),
]);
$o13 = DataBindingExpander::expand($docEdited, $data);
$j13 = json_encode($o13);
check('edição por bloco: item n2 mantém o texto próprio', str_contains($j13, 'SO-NESTE'));
check('edição por bloco: demais itens resolvem normal', str_contains($j13, 'AAA') && str_contains($j13, 'CCC') && ! str_contains($j13, 'BBB'));

echo $failures === 0 ? "\nTODOS OS TESTES PASSARAM\n" : "\n$failures TESTE(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
