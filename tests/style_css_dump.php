<?php

declare(strict_types=1);

// CLI de PARIDADE: lê as fixtures de BlockStyles (JSON no stdin, formato
// { fixtures: { nome: styles, ... } }) e imprime { nome: cssString } com a
// saída CRUA de StyleHelpers::blockStyles — o lado PHP do golden parity de
// serialização de estilo. A canonicalização/diff fica no JS (css-canon.mjs).
//
//   cat style-fixtures.json | php style_css_dump.php
//
// Roda standalone (sem bootar o Laravel). `e()` (escape) é stubado.

if (! function_exists('e')) {
    function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
// Stub mínimo de collect()->map()->implode() usado por gradientCSS.
if (! function_exists('collect')) {
    function collect(array $items): object {
        return new class($items) {
            public function __construct(private array $items) {}
            public function map(callable $fn): self { return new self(array_map($fn, $this->items)); }
            public function implode(string $glue): string { return implode($glue, $this->items); }
        };
    }
}

require __DIR__ . '/../src/StyleHelpers.php';

use PdfBlock\Laravel\StyleHelpers;

$raw = stream_get_contents(STDIN);
$data = json_decode($raw, true);
if (! is_array($data) || ! isset($data['fixtures']) || ! is_array($data['fixtures'])) {
    fwrite(STDERR, "esperado JSON { fixtures: { nome: styles } }\n");
    exit(1);
}

$out = [];
foreach ($data['fixtures'] as $name => $styles) {
    if (! is_array($styles)) continue;
    $out[$name] = StyleHelpers::blockStyles($styles);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
