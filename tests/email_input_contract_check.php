<?php

declare(strict_types=1);

// Contrato de ENTRADA do modo e-mail (auditoria ago/2026, Fase 2.1).
//
// As blades de e-mail percorrem `blocks` — um documento v3 chegava ao
// `EmailRenderer` e saía com o shell HTML certo e o corpo VAZIO, sem erro
// nenhum: `document.blade.php` faz `@foreach($doc['blocks'] ?? [] …)`, e num v3
// essa chave não existe. Falha silenciosa é exatamente a classe de bug que a
// auditoria mapeou; aqui ela vira teste.
//
// Testa `EmailRenderer::toEmailDocument()` — o achatamento isolado do render,
// para rodar sem bootar o Laravel (as views exigiriam o framework de pé).

require __DIR__ . '/../src/DocumentMigrator.php';
require __DIR__ . '/../src/Email/EmailRenderer.php';

use PdfBlock\Laravel\DocumentMigrator;
use PdfBlock\Laravel\Email\EmailRenderer;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (! $cond) { $failures++; }
}

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

/** Bloco de texto com conteúdo TipTap — é o que precisa sobreviver ao achatamento. */
function txt(string $id, string $content): array
{
    return ['id' => $id, 'type' => 'text', 'meta' => dm(), 'styles' => ds(), 'content' => [
        'type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $content]]]],
    ]];
}

$v2 = [
    'id' => 'doc-email', 'version' => '2.0.0',
    'meta' => ['title' => 'Boletim', 'mode' => 'email'],
    'pageSettings' => ['paperSize' => ['preset' => 'a4', 'width' => 210, 'height' => 297], 'orientation' => 'portrait', 'margins' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0], 'defaultFontFamily' => 'Arial, sans-serif'],
    'globalStyles' => ['pageBackground' => '#f4f4f4', 'contentBackground' => '#ffffff', 'defaultFontColor' => '#333333'],
    'emailSettings' => ['width' => 600, 'preheader' => 'Resumo da semana', 'bodyBackground' => '#ffffff', 'pageBackground' => '#f4f4f4', 'fontStack' => 'Arial, sans-serif'],
    'blocks' => [[
        'id' => 'stripe-1', 'type' => 'stripe', 'meta' => dm(), 'styles' => ds(),
        'contentMaxWidth' => 0, 'contentAlignment' => 'center',
        'children' => [[
            'id' => 'struct-1', 'type' => 'structure', 'meta' => dm(), 'styles' => ds(),
            'columnGap' => 0, 'verticalAlignment' => 'top',
            'columns' => [['id' => 'col-1', 'width' => 100, 'styles' => ds(), 'children' => [txt('leaf-1', 'Olá')]]],
        ]],
    ]],
];

// ── v2 passa inalterado: o caminho de hoje não muda ──
check('v2 atravessa sem tocar', EmailRenderer::toEmailDocument($v2) === $v2);

// ── v3 é achatado em vez de sair vazio ──
$v3 = DocumentMigrator::v2ToV3($v2);
check('fixture v3 é reconhecida como v3', DocumentMigrator::isV3($v3));
check('v3 CRU não tem `blocks` (era o que sumia)', ! isset($v3['blocks']));

$flat = EmailRenderer::toEmailDocument($v3);
check('v3 achatado ganha `blocks`', is_array($flat['blocks'] ?? null));
check('v3 achatado NÃO fica vazio', count($flat['blocks'] ?? []) > 0);

/** Ids das folhas, na ordem — o conteúdo tem de atravessar, não só a casca. */
function leaves(array $blocks): array
{
    $out = [];
    $walk = function (array $children) use (&$walk, &$out) {
        foreach ($children as $child) {
            if (($child['type'] ?? '') === 'structure') {
                foreach ($child['columns'] ?? [] as $col) { $walk($col['children'] ?? []); }
                continue;
            }
            $out[] = $child['id'] ?? '?';
        }
    };
    foreach ($blocks as $stripe) { $walk($stripe['children'] ?? []); }

    return $out;
}
check('a folha de texto sobrevive ao achatamento', leaves($flat['blocks']) === ['leaf-1']);

// `emailSettings` é o que o wrapper do e-mail usa (largura, preheader, fundos):
// perder isso na migração daria e-mail com o corpo certo e a moldura errada.
check('emailSettings sobrevive à ida e volta', ($flat['emailSettings']['width'] ?? null) === 600
    && ($flat['emailSettings']['preheader'] ?? null) === 'Resumo da semana');
check('meta.mode continua email', ($flat['meta']['mode'] ?? null) === 'email');

exit($failures > 0 ? 1 : 0);
