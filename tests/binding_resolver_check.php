<?php

declare(strict_types=1);

// Teste standalone (sem PHPUnit/Composer) do BindingResolver.
// Rodar: docker run --rm -v $PWD:/app -w /app php:8.3-cli php packages/laravel/tests/binding_resolver_check.php

require __DIR__ . '/../src/Data/ValueFormatter.php';
require __DIR__ . '/../src/Data/RichText.php';
require __DIR__ . '/../src/Data/BindingResolver.php';

use PdfBlock\Laravel\Data\BindingResolver;
use PdfBlock\Laravel\Data\RichText;

$failures = 0;
function check(string $name, mixed $got, mixed $expected): void
{
    global $failures;
    $ok = $got === $expected;
    if (! $ok) {
        $failures++;
        echo "FAIL: $name\n  esperado: " . json_encode($expected) . "\n  obtido:   " . json_encode($got) . "\n";
    } else {
        echo "ok: $name\n";
    }
}

// dotGet — dotted + índice de array + escalar→string
check('dotGet dotted', BindingResolver::dotGet(['a' => ['b' => 'x']], 'a.b'), 'x');
check('dotGet index', BindingResolver::dotGet(['items' => [['title' => 'T0'], ['title' => 'T1']]], 'items[1].title'), 'T1');
check('dotGet number→string', BindingResolver::dotGet(['n' => 42], 'n'), '42');
check('dotGet missing→default', BindingResolver::dotGet(['a' => 1], 'a.b.c', 'D'), 'D');

// dotGetRaw — preserva arrays
$raw = BindingResolver::dotGetRaw(['src' => [['title' => 'A'], ['title' => 'B']]], 'src');
check('dotGetRaw array count', is_array($raw) ? count($raw) : -1, 2);

// resolveString — único e múltiplo, faltante → ''
check('resolveString multi', BindingResolver::resolveString('{{bind:title}} — {{bind:link}}', ['title' => 'Olá', 'link' => 'u']), 'Olá — u');
check('resolveString missing', BindingResolver::resolveString('a{{bind:x}}b', []), 'ab');

// resolve — props aninhados
$props = ['title' => '{{bind:title}}', 'url' => 'https://{{bind:host}}/x', 'n' => 3];
check('resolve nested props', BindingResolver::resolve($props, ['title' => 'T', 'host' => 'ex.com']), ['title' => 'T', 'url' => 'https://ex.com/x', 'n' => 3]);

// resolve — nó TipTap variable → nó de texto
$content = [
    'type' => 'doc',
    'content' => [[
        'type' => 'paragraph',
        'content' => [
            ['type' => 'text', 'text' => 'Olá '],
            ['type' => 'variable', 'attrs' => ['path' => 'title', 'label' => 'Título']],
        ],
    ]],
];
$resolved = BindingResolver::resolve($content, ['title' => 'Mundo']);
check('resolve variable node', $resolved['content'][0]['content'][1], ['type' => 'text', 'text' => 'Mundo']);

// formatMap — formatação por campo + paridade objeto→vazio
check('resolveString currency', BindingResolver::resolveString('Total: {{bind:price}}', ['price' => 1234.5], ['price' => 'currency:BRL']), 'Total: R$ 1.234,50');
check('resolveString date', BindingResolver::resolveString('{{bind:d}}', ['d' => '2026-06-13'], ['d' => 'date:dd/MM/yyyy']), '13/06/2026');
check('resolveString objeto→vazio', BindingResolver::resolveString('x{{bind:obj}}y', ['obj' => ['a' => 1]]), 'xy');
$vnode = ['type' => 'variable', 'attrs' => ['path' => 'n', 'label' => 'N']];
check('resolve variable formatado', BindingResolver::resolve($vnode, ['n' => 1234.5], ['n' => 'number:2']), ['type' => 'text', 'text' => '1.234,50']);
// attrs.format (formato POR INSERÇÃO) vence o formatMap — espelha o TS.
$vfmt = ['type' => 'variable', 'attrs' => ['path' => 'd', 'label' => 'Data', 'format' => "date:MMMM 'de' yyyy"]];
check('attrs.format vence o formatMap', BindingResolver::resolve($vfmt, ['d' => '2026-03-02'], ['d' => 'date:dd/MM/yyyy']), ['type' => 'text', 'text' => 'março de 2026']);

// longtext RICO (kind 'rich'): fragmento inline EMENDADO no content — paridade com richtext.ts.
check('RichText marks', RichText::toInlineNodes('a <strong>b</strong>'), [
    ['type' => 'text', 'text' => 'a '],
    ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'bold']]],
]);
check('RichText parágrafos → hardBreak', RichText::toPlain('<p>a</p><p>b</p>'), "a\nb");
check('RichText tag desconhecida descartada', RichText::toPlain('<span>a</span> &amp; b'), 'a & b');
$paraRich = ['type' => 'paragraph', 'content' => [
    ['type' => 'text', 'text' => 'Obs: '],
    ['type' => 'variable', 'attrs' => ['path' => 'obs', 'label' => 'Obs']],
]];
$resolvedRich = BindingResolver::resolve($paraRich, ['obs' => 'a <b>forte</b><br>fim'], ['obs' => 'rich']);
check('variable rich vira fragmento emendado', $resolvedRich['content'], [
    ['type' => 'text', 'text' => 'Obs: '],
    ['type' => 'text', 'text' => 'a '],
    ['type' => 'text', 'text' => 'forte', 'marks' => [['type' => 'bold']]],
    ['type' => 'hardBreak'],
    ['type' => 'text', 'text' => 'fim'],
]);
check('NÃO achata rows de tabela', BindingResolver::resolve([['a', 'b'], ['c', '{{bind:x}}']], ['x' => 'X']), [['a', 'b'], ['c', 'X']]);

// MARKS do chip (negrito/cor aplicadas ao nó variable) → carregadas ao texto
// resolvido; no RICO, mesclam com as internas (interna vence no mesmo tipo).
// Espelha resolveBindingsDeep (bindings.ts).
$vMarked = ['type' => 'variable', 'attrs' => ['path' => 'title', 'label' => 'T'], 'marks' => [['type' => 'bold']]];
check('marks do chip no texto resolvido', BindingResolver::resolve($vMarked, ['title' => 'X']), ['type' => 'text', 'text' => 'X', 'marks' => [['type' => 'bold']]]);
$paraMarkedRich = ['type' => 'paragraph', 'content' => [
    ['type' => 'variable', 'attrs' => ['path' => 'obs', 'label' => 'Obs'], 'marks' => [['type' => 'bold'], ['type' => 'italic']]],
]];
$resolvedMarkedRich = BindingResolver::resolve($paraMarkedRich, ['obs' => 'a <b>b</b>'], ['obs' => 'rich']);
check('marks do chip mescladas no fragmento rico', $resolvedMarkedRich['content'], [
    ['type' => 'text', 'text' => 'a ', 'marks' => [['type' => 'bold'], ['type' => 'italic']]],
    ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'italic'], ['type' => 'bold']]],
]);

// ─── Parágrafo rico COMPLETO (blocos: listas, font-size) — paridade richtext.ts ──
check('toBlocks: parágrafo + lista', RichText::toBlocks('<p>a</p><ul><li><p>um</p></li></ul>'), [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a']]],
    ['type' => 'bulletList', 'content' => [
        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'um']]]]],
    ]],
]);
check('toBlocks: font-size vira textStyle', RichText::toBlocks('<p><span style="font-size: 18px">g</span>n</p>'), [
    ['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'g', 'marks' => [['type' => 'textStyle', 'attrs' => ['fontSize' => '18px']]]],
        ['type' => 'text', 'text' => 'n'],
    ]],
]);
check('inline degrade: lista vira linhas com marcador', RichText::toPlain('<ul><li><p>um</p></li><li><p>dois</p></li></ul>'), "• um\n• dois");
check('inline degrade: numerada', RichText::toPlain('<ol><li><p>um</p></li><li><p>dois</p></li></ol>'), "1. um\n2. dois");

// Chip rico SOZINHO no parágrafo → blocos reais emendados no lugar do parágrafo.
$docLone = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['path' => 'obs', 'label' => 'Obs']]]],
]];
$resolvedLone = BindingResolver::resolve($docLone, ['obs' => '<p>intro</p><ul><li><p>um</p></li></ul>'], ['obs' => 'rich']);
check('chip sozinho vira blocos (parágrafo + lista)', $resolvedLone['content'], [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'intro']]],
    ['type' => 'bulletList', 'content' => [
        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'um']]]]],
    ]],
]);
// Chip no MEIO da frase → lista degrada para linhas com marcador (inline).
$paraMid = ['type' => 'paragraph', 'content' => [
    ['type' => 'text', 'text' => 'Itens: '],
    ['type' => 'variable', 'attrs' => ['path' => 'obs', 'label' => 'Obs']],
]];
$resolvedMid = BindingResolver::resolve($paraMid, ['obs' => '<ul><li><p>um</p></li><li><p>dois</p></li></ul>'], ['obs' => 'rich']);
check('chip no meio degrada lista p/ linhas', $resolvedMid['content'], [
    ['type' => 'text', 'text' => 'Itens: '],
    ['type' => 'text', 'text' => '• '], ['type' => 'text', 'text' => 'um'],
    ['type' => 'hardBreak'],
    ['type' => 'text', 'text' => '• '], ['type' => 'text', 'text' => 'dois'],
]);
// Blocos herdam marks do chip e attrs do parágrafo (espelha o TS).
$docMarked = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [
        ['type' => 'variable', 'attrs' => ['path' => 'obs', 'label' => 'Obs'], 'marks' => [['type' => 'bold']]],
    ]],
]];
$resolvedMarked = BindingResolver::resolve($docMarked, ['obs' => '<p>a</p><p><i>b</i></p>'], ['obs' => 'rich']);
check('blocos herdam marks do chip + attrs do parágrafo', $resolvedMarked['content'], [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'bold']]]], 'attrs' => ['textAlign' => 'center']],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'bold'], ['type' => 'italic']]]], 'attrs' => ['textAlign' => 'center']],
]);

echo $failures === 0 ? "\nTODOS OS TESTES PASSARAM\n" : "\n$failures TESTE(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
