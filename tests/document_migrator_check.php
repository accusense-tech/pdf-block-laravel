<?php

declare(strict_types=1);

// Teste standalone do DocumentMigrator (v2↔v3). Garante: detecção de v3, achatar
// stripe trivial, multi-coluna→columnSet, banner→group, e round-trip v2→v3→v2
// preservando os ids de blocos-folha. Rodar via docker (sem bootar Laravel).

require __DIR__ . '/../src/DocumentMigrator.php';

use PdfBlock\Laravel\DocumentMigrator;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (! $cond) { $failures++; }
}

// Estilos default COMPLETOS (como o editor envia) para o flatten valer.
function ds(): array
{
    return [
        'padding' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
        'margin' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
        'border' => [
            'top' => ['width' => 0, 'style' => 'none', 'color' => '#000000'],
            'right' => ['width' => 0, 'style' => 'none', 'color' => '#000000'],
            'bottom' => ['width' => 0, 'style' => 'none', 'color' => '#000000'],
            'left' => ['width' => 0, 'style' => 'none', 'color' => '#000000'],
        ],
        'borderRadius' => ['topLeft' => 0, 'topRight' => 0, 'bottomRight' => 0, 'bottomLeft' => 0],
        'background' => ['type' => 'solid', 'color' => 'transparent'],
        'shadow' => ['enabled' => false, 'offsetX' => 0, 'offsetY' => 2, 'blur' => 8, 'spread' => 0, 'color' => 'rgba(0,0,0,0.15)'],
        'opacity' => 1,
    ];
}
function dm(): array { return ['hideOnExport' => false, 'locked' => false, 'breakBefore' => false, 'breakAfter' => false, 'keepTogether' => false]; }
function txt(string $id): array { return ['id' => $id, 'type' => 'text', 'meta' => dm(), 'styles' => ds()]; }
function col(string $id, int $w, array $children): array { return ['id' => $id, 'width' => $w, 'styles' => ds(), 'children' => $children]; }
function st(string $id, array $cols, array $extra = []): array { return array_merge(['id' => $id, 'type' => 'structure', 'meta' => dm(), 'styles' => ds(), 'columnGap' => 0, 'verticalAlignment' => 'top', 'columns' => $cols], $extra); }
function stripe(string $id, array $kids, array $extra = []): array { return array_merge(['id' => $id, 'type' => 'stripe', 'meta' => dm(), 'styles' => ds(), 'contentMaxWidth' => 0, 'contentAlignment' => 'center', 'children' => $kids], $extra); }
function docOf(array $blocks): array { return ['id' => 'd1', 'version' => '2.0.0', 'meta' => [], 'pageSettings' => ['paperSize' => ['preset' => 'a4', 'width' => 210, 'height' => 297], 'orientation' => 'portrait', 'margins' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10], 'defaultFontFamily' => 'Spectral, serif'], 'globalStyles' => ['pageBackground' => '#fff', 'contentBackground' => '#fff', 'defaultFontColor' => '#333'], 'blocks' => $blocks]; }

function leaves(array $blocks): array
{
    $o = [];
    $walk = function (array $children) use (&$walk, &$o) {
        foreach ($children as $ch) {
            if (($ch['type'] ?? '') === 'structure') foreach ($ch['columns'] as $c) $walk($c['children']);
            else $o[] = $ch['id'];
        }
    };
    foreach ($blocks as $s) foreach ($s['children'] as $structure) foreach ($structure['columns'] as $c) $walk($c['children']);
    return $o;
}

// (1) detecção de v3
check('isV3 reconhece sections', DocumentMigrator::isV3(['sections' => []]));
check('isV3 reconhece version 3.0.0', DocumentMigrator::isV3(['version' => '3.0.0']));
check('isV3 falso p/ v2', ! DocumentMigrator::isV3(['blocks' => []]));

// (2) achata stripe trivial → fluxo direto
$v3 = DocumentMigrator::v2ToV3(docOf([stripe('s1', [st('st1', [col('c1', 100, [txt('t1'), txt('t2')])])])]));
$flow = $v3['sections'][0]['flow'];
check('1 seção', count($v3['sections']) === 1);
check('stripe trivial achatada (fluxo = 2 folhas)', count($flow) === 2 && ($flow[0]['id'] ?? '') === 't1' && ($flow[1]['id'] ?? '') === 't2');
check('pageSetup veio de pageSettings', ($v3['sections'][0]['pageSetup']['orientation'] ?? '') === 'portrait');

// (3) multi-coluna → columnSet
$v3b = DocumentMigrator::v2ToV3(docOf([stripe('s1', [st('st1', [col('c1', 30, [txt('a')]), col('c2', 70, [txt('b')])], ['columnGap' => 12])])]));
$cs = $v3b['sections'][0]['flow'][0];
check('multi-coluna vira columnSet', ($cs['type'] ?? '') === 'columnSet' && count($cs['columns']) === 2 && $cs['columns'][0]['width'] === 30);

// (4) banner → group
$v3c = DocumentMigrator::v2ToV3(docOf([stripe('s1', [st('st1', [col('c1', 100, [txt('h')])], ['variant' => 'banner', 'backgroundImage' => 'x.png', 'minHeight' => 300])])]));
$g = $v3c['sections'][0]['flow'][0];
check('banner vira group banner', ($g['type'] ?? '') === 'group' && ($g['variant'] ?? '') === 'banner' && ($g['banner']['backgroundImage'] ?? '') === 'x.png');

// (5) round-trip v2→v3→v2 preserva ids de folha em ordem
$v2 = docOf([
    stripe('s1', [st('st1', [col('c1', 100, [txt('t1')])])]),
    stripe('s2', [st('st2', [col('c2', 50, [txt('a'), st('nst', [col('nc', 100, [txt('deep')])])]), col('c3', 50, [txt('b')])])], ['styles' => array_merge(ds(), ['padding' => ['top' => 8, 'right' => 0, 'bottom' => 0, 'left' => 0]])]),
]);
$back = DocumentMigrator::v3ToV2(DocumentMigrator::v2ToV3($v2));
check('round-trip preserva ids de folha', leaves($v2['blocks']) === leaves($back['blocks']));
check('round-trip leaves = [t1,a,deep,b]', leaves($v2['blocks']) === ['t1', 'a', 'deep', 'b']);

// effectivePageSettings: v3 lê sections[0].pageSetup (drivers medem o viewport
// na largura CERTA — payload v3 cru não tem pageSettings na raiz); v2 lê a raiz.
$v3ps = DocumentMigrator::effectivePageSettings([
    'version' => '3.0.0',
    'sections' => [['id' => 's', 'type' => 'section', 'pageSetup' => ['paperSize' => ['preset' => 'custom', 'width' => 338.6667, 'height' => 1070], 'orientation' => 'portrait'], 'flow' => []]],
]);
check('effectivePageSettings v3 → paperSize da seção', ($v3ps['paperSize']['width'] ?? 0) === 338.6667);
$v2ps = DocumentMigrator::effectivePageSettings(['pageSettings' => ['paperSize' => ['width' => 210, 'height' => 297]], 'blocks' => []]);
check('effectivePageSettings v2 → raiz', ($v2ps['paperSize']['width'] ?? 0) === 210);
check('effectivePageSettings sem nada → []', DocumentMigrator::effectivePageSettings(['blocks' => []]) === []);

// ─── Carry de container (`row` / `typography`) ─────────────────────
// Não têm equivalente estrutural no v2: precisam viajar EM CIMA da projeção,
// senão o host (que grava a projeção) devolve o documento sem eles.
$typo = ['fontSize' => 20, 'fontColor' => '#123456'];
$row = ['gap' => 16, 'justify' => 'center'];
$v3carry = [
    'version' => '3.0.0',
    'sections' => [['id' => 'sec', 'type' => 'section', 'pageSetup' => [], 'flow' => [
        ['id' => 'g1', 'type' => 'group', 'meta' => dm(), 'styles' => ds(), 'contentMaxWidth' => 500, 'typography' => $typo, 'row' => $row, 'flow' => [txt('t1')]],
        ['id' => 'cs1', 'type' => 'columnSet', 'meta' => dm(), 'styles' => ds(), 'columnGap' => 0, 'verticalAlignment' => 'top', 'typography' => ['fontFamily' => 'Inter'], 'columns' => [
            ['id' => 'ca', 'width' => 50, 'styles' => ds(), 'typography' => $typo, 'flow' => [txt('a')]],
            ['id' => 'cb', 'width' => 50, 'styles' => ds(), 'flow' => [txt('b')]],
        ]],
    ]]],
];
$proj = DocumentMigrator::v3ToV2($v3carry);
check('projeção: grupo leva typography/row', ($proj['blocks'][0]['typography'] ?? null) === $typo && ($proj['blocks'][0]['row'] ?? null) === $row);
$projCs = $proj['blocks'][1]['children'][0];
check('projeção: linha leva typography', ($projCs['typography'] ?? null) === ['fontFamily' => 'Inter']);
check('projeção: coluna leva typography', ($projCs['columns'][0]['typography'] ?? null) === $typo);
check('projeção: coluna sem typography fica sem', ! isset($projCs['columns'][1]['typography']));

$backCarry = DocumentMigrator::v2ToV3($proj);
$g = $backCarry['sections'][0]['flow'][0];
$cs = $backCarry['sections'][0]['flow'][1];
check('volta: grupo reidrata typography/row', ($g['typography'] ?? null) === $typo && ($g['row'] ?? null) === $row);
check('volta: linha e coluna reidratam typography', ($cs['typography'] ?? null) === ['fontFamily' => 'Inter'] && ($cs['columns'][0]['typography'] ?? null) === $typo);

// Wrapper trivial COM tipografia não pode ser dissolvido (a fonte sumiria) — e
// volta como GRUPO: uma caixa de 1 coluna trivial com algo próprio é o que
// `groupToStructure` projeta, não uma linha de colunas (espelha migrate.ts).
$v2typo = docOf([stripe('sT', [array_merge(st('stT', [col('cT', 100, [txt('tT')])]), ['typography' => $typo])])]);
$v3typo = DocumentMigrator::v2ToV3($v2typo);
check('wrapper trivial com typography sobrevive (como grupo)', ($v3typo['sections'][0]['flow'][0]['type'] ?? '') === 'group'
    && ($v3typo['sections'][0]['flow'][0]['typography'] ?? null) === $typo);

// GRUPO aninhado esticado: `fillWidth`/`fillHeight` viajam no v2 e voltam.
$v3grp = DocumentMigrator::v2ToV3(docOf([stripe('sG', [
    array_merge(st('stG', [col('cG', 100, [txt('tG')])]), ['fillWidth' => true, 'fillHeight' => true]),
])]));
$gNode = $v3grp['sections'][0]['flow'][0];
check('grupo aninhado: fillWidth/fillHeight reidratam', ($gNode['type'] ?? '') === 'group'
    && ($gNode['fillWidth'] ?? null) === true && ($gNode['fillHeight'] ?? null) === true);

// Banner ESTICADO (`fillHeight`) sobrevive à ida e volta (o campo é achatado no v2).
$v3fill = [
    'version' => '3.0.0',
    'sections' => [['id' => 'sec', 'type' => 'section', 'pageSetup' => [], 'flow' => [
        ['id' => 'cs', 'type' => 'columnSet', 'meta' => dm(), 'styles' => ds(), 'columnGap' => 0, 'verticalAlignment' => 'stretch', 'columns' => [
            ['id' => 'c1', 'width' => 50, 'styles' => ds(), 'flow' => [txt('t0')]],
            ['id' => 'c2', 'width' => 50, 'styles' => ds(), 'flow' => [
                ['id' => 'bn', 'type' => 'group', 'variant' => 'banner', 'meta' => dm(), 'styles' => ds(),
                 'banner' => ['minHeight' => 120, 'fillHeight' => true], 'flow' => [txt('bt')]],
            ]],
        ]],
    ]]],
];
$projFill = DocumentMigrator::v3ToV2($v3fill);
$stFill = $projFill['blocks'][0]['children'][0]['columns'][1]['children'][0];
check('projeção: banner leva fillHeight (achatado)', ($stFill['fillHeight'] ?? null) === true && ($stFill['variant'] ?? '') === 'banner');
$backFill = DocumentMigrator::v2ToV3($projFill);
$bnBack = $backFill['sections'][0]['flow'][0]['columns'][1]['flow'][0];
check('volta: fillHeight reidrata em banner.fillHeight', ($bnBack['banner']['fillHeight'] ?? null) === true);

echo "\n" . ($failures === 0 ? "TODOS OS TESTES PASSARAM\n" : "$failures FALHA(S)\n");
exit($failures === 0 ? 0 : 1);
