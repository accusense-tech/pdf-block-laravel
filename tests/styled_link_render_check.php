<?php

declare(strict_types=1);

// Teste de render do StyledLink (mark link estilizável) via TiptapConverter real.
//
// Usa o autoload do PRÓPRIO pacote (tem tiptap-php e o PSR-4 de PdfBlock\Laravel).
// Antes apontava para `/w/apps/laravel-sandbox/vendor/autoload.php` — um caminho
// absoluto do mount de um container que não existe mais, então o check morria com
// fatal error. Ninguém percebeu porque nada executava os checks: eles só entraram
// no CI na Fase 0 da auditoria de ago/2026.

require __DIR__ . '/../vendor/autoload.php';

use PdfBlock\Laravel\TiptapConverter;

$conv = new TiptapConverter;

$doc = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'content' => [
        // link externo estilizado: cor vermelha + SEM sublinhado
        ['type' => 'text', 'text' => 'externo', 'marks' => [
            ['type' => 'link', 'attrs' => ['href' => 'https://ex.com', 'color' => '#ff0000', 'underline' => false]],
        ]],
        // link âncora (#bloco) com padrão: herda cor + sublinhado
        ['type' => 'text', 'text' => 'salto', 'marks' => [
            ['type' => 'link', 'attrs' => ['href' => '#bloco-1']],
        ]],
    ]],
]];

$html = $conv->toHtml($doc);
echo $html, "\n---\n";

$failures = 0;
function has(string $h, string $needle, string $name): void
{
    global $failures;
    if (str_contains($h, $needle)) { echo "ok: $name\n"; } else { $failures++; echo "FAIL: $name (faltou: $needle)\n"; }
}
function hasnt(string $h, string $needle, string $name): void
{
    global $failures;
    if (! str_contains($h, $needle)) { echo "ok: $name\n"; } else { $failures++; echo "FAIL: $name (não devia ter: $needle)\n"; }
}

has($html, 'class="pdfb-link"', 'classe pdfb-link presente');
has($html, 'color:#ff0000', 'cor específica no style inline');
has($html, 'text-decoration:none', 'sublinhado removido (underline=false)');
has($html, 'href="#bloco-1"', 'âncora href preservado');
has($html, 'text-decoration:underline', 'link âncora com sublinhado padrão');
hasnt($html, 'target="_blank"', 'sem target=_blank forçado (âncora não abre nova aba)');
hasnt($html, 'color:#5b8cff', 'sem azul nativo hardcoded');

echo $failures === 0 ? "\nTODOS OK\n" : "\n$failures FALHA(S)\n";
exit($failures === 0 ? 0 : 1);
