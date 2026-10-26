<?php

declare(strict_types=1);

// Check do middleware que blinda o JSON do TipTap contra o TrimStrings/
// ConvertEmptyStringsToNull GLOBAIS do Laravel. Precisa do framework (usa
// Illuminate\Http\Request), então roda sobre o vendor do pacote:
//
//   docker run --rm -v "$PWD/packages/laravel":/app -w /app php:8.4-cli \
//     php tests/preserve_document_whitespace_check.php
//
// (rode `composer install` no pacote antes — vendor/ não é versionado)

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use PdfBlock\Laravel\Http\Middleware\PreserveDocumentWhitespace;

$failures = 0;

function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual === $expected) {
        echo "ok: $name\n";
        return;
    }
    $failures++;
    echo "FAIL: $name\n  esperado: " . json_encode($expected, JSON_UNESCAPED_UNICODE)
        . "\n  obtido:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
}

/** Monta o request JSON e roda a pilha REAL do Laravel: globais → nosso middleware. */
function pipeline(array $body, string ...$keys): Request
{
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $request = Request::create('/api/pdf-block/templates', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $json);

    $next = static fn (Request $r): Request => $r;

    // Ordem de produção: os globais do framework rodam ANTES do middleware de rota.
    $run = static function (object $mw, Request $r, Closure $next) use ($keys) {
        return $mw instanceof PreserveDocumentWhitespace
            ? $mw->handle($r, $next, ...$keys)
            : $mw->handle($r, $next);
    };

    return $run(new TrimStrings, $request, static fn (Request $r) => $run(
        new ConvertEmptyStringsToNull,
        $r,
        static fn (Request $r2) => $run(new PreserveDocumentWhitespace, $r2, $next)
    ));
}

// Documento com os três estragos reportados pelos usuários.
$doc = [
    'meta' => ['title' => 'Relatório'],
    'blocks' => [[
        'type' => 'text',
        'content' => ['type' => 'doc', 'content' => [
            // 1) espaço ANTES do negrito: sem ele as palavras colam
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'palavra '],
                ['type' => 'text', 'text' => 'negrito', 'marks' => [['type' => 'bold']]],
            ]],
            // 2) recuo à esquerda
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '   com recuo']]],
            // 3) linha só de espaços → vira null e o ProseMirror derruba o bloco
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '    ']]],
        ]],
    ]],
];

// ── 1. O estrago EXISTE sem o middleware (guarda contra falso-positivo) ──────
$semProtecao = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
    json_encode(['document' => $doc], JSON_UNESCAPED_UNICODE));
(new TrimStrings)->handle($semProtecao, static fn (Request $r) => (new ConvertEmptyStringsToNull)->handle($r, static fn ($r2) => $r2));
$danificado = $semProtecao->input('document');

check(
    'sem o middleware o TrimStrings come o espaço antes do negrito',
    $danificado['blocks'][0]['content']['content'][0]['content'][0]['text'],
    'palavra'
);
check(
    'sem o middleware a linha só de espaços vira null (nó inválido p/ o ProseMirror)',
    $danificado['blocks'][0]['content']['content'][2]['content'][0]['text'],
    null
);

// ── 2. Com o middleware o documento chega intacto ────────────────────────────
$protegido = pipeline(['name' => '  Meu template  ', 'document' => $doc], 'document');
$restaurado = $protegido->input('document');

check('espaço antes do negrito preservado', $restaurado['blocks'][0]['content']['content'][0]['content'][0]['text'], 'palavra ');
check('recuo à esquerda preservado', $restaurado['blocks'][0]['content']['content'][1]['content'][0]['text'], '   com recuo');
check('linha só de espaços preservada (não vira null)', $restaurado['blocks'][0]['content']['content'][2]['content'][0]['text'], '    ');
check('documento inteiro idêntico ao enviado', $restaurado, $doc);

// ── 3. O middleware é CIRÚRGICO: o resto do payload segue aparado ────────────
check('name continua sendo aparado pelo TrimStrings', $protegido->input('name'), 'Meu template');

// ── 4. Chave ausente NÃO é criada (senão quebra o `sometimes` do validador) ──
$parcial = pipeline(['name' => 'só o nome'], 'document');
check('chave ausente não é inventada', $parcial->has('document'), false);

// ── 5. Múltiplas chaves (módulos mandam `block`, não `document`) ─────────────
$multi = pipeline(['document' => $doc, 'block' => ['type' => 'text', 'x' => ' a '], 'name' => ' n '], 'document', 'block');
check('block também é restaurado quando pedido', $multi->input('block')['x'], ' a ');
check('name segue aparado com múltiplas chaves', $multi->input('name'), 'n');

// ── 6. Default sem argumento = `document` ───────────────────────────────────
$semArgs = pipeline(['document' => $doc]);
check('sem argumento protege `document`', $semArgs->input('document'), $doc);

// ── 7. Request não-JSON passa reto (form-urlencoded não é nosso caso) ───────
$form = Request::create('/x', 'POST', ['document' => ' a ']);
$saida = (new PreserveDocumentWhitespace)->handle($form, static fn (Request $r) => $r, 'document');
check('request não-JSON passa sem alteração', $saida->input('document'), ' a ');

// ── 8. Corpo inválido não explode ───────────────────────────────────────────
$quebrado = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{nao é json');
$saida2 = (new PreserveDocumentWhitespace)->handle($quebrado, static fn (Request $r) => $r, 'document');
check('JSON inválido não lança (deixa a validação responder)', $saida2->has('document'), false);

echo $failures === 0 ? "\nTODOS OS CHECKS PASSARAM\n" : "\n$failures CHECK(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
