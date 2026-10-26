<?php

declare(strict_types=1);

// Teste standalone de StyleHelpers::normalizeStyles + primitivos endurecidos.
// Garante que a DSL ESPARSA (styles parcial/ausente) gera CSS válido SEM warnings
// e IDÊNTICO ao da forma completa. Rodar via docker php:8.3-cli.

// Qualquer warning/notice (ex.: índice de array ausente) vira exceção → falha o teste.
set_error_handler(function ($severity, $message) {
    throw new \ErrorException($message, 0, $severity);
});

if (! function_exists('e')) {
    function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
// Polyfill mínimo do helper collect() do Laravel (usado em gradientCSS).
if (! function_exists('collect')) {
    function collect($items) {
        return new class($items) {
            public function __construct(private array $i) {}
            public function map(callable $f) { return new self(array_map($f, $this->i)); }
            public function implode(string $glue): string { return implode($glue, $this->i); }
        };
    }
}

require __DIR__ . '/../src/StyleHelpers.php';

use PdfBlock\Laravel\StyleHelpers as S;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? "ok: " : "FAIL: ") . $name . "\n";
    if (! $cond) { $failures++; }
}
function eq(string $name, $a, $b): void
{
    check($name . ($a === $b ? '' : "  (got=" . var_export($a, true) . " exp=" . var_export($b, true) . ")"), $a === $b);
}

// ── Defaults completos (espelho de DEFAULT_BLOCK_STYLES do TS) ──
$full = S::normalizeStyles([]);
eq('normalize([]) padding', $full['padding'], ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]);
eq('normalize([]) border.top', $full['border']['top'], ['width' => 0, 'style' => 'none', 'color' => '#000000']);
eq('normalize([]) borderRadius', $full['borderRadius'], ['topLeft' => 0, 'topRight' => 0, 'bottomRight' => 0, 'bottomLeft' => 0]);
eq('normalize([]) background', $full['background'], ['type' => 'solid', 'color' => 'transparent']);
eq('normalize([]) shadow', $full['shadow'], ['enabled' => false, 'offsetX' => 0, 'offsetY' => 2, 'blur' => 8, 'spread' => 0, 'color' => 'rgba(0,0,0,0.15)']);
eq('normalize([]) opacity', $full['opacity'], 1);

// ── Parcial preenche o resto ──
$p = S::normalizeStyles(['padding' => ['top' => 8]]);
eq('partial padding fills', $p['padding'], ['top' => 8, 'right' => 0, 'bottom' => 0, 'left' => 0]);

$b = S::normalizeStyles(['border' => ['top' => ['width' => 2, 'style' => 'solid']]]);
eq('partial border.top color default', $b['border']['top'], ['width' => 2, 'style' => 'solid', 'color' => '#000000']);
eq('partial border.right default', $b['border']['right'], ['width' => 0, 'style' => 'none', 'color' => '#000000']);

// opacity 0 deve ser preservado (não virar 1)
eq('opacity 0 preserved', S::normalizeStyles(['opacity' => 0])['opacity'], 0);

// ── Primitivos não quebram com vazio/parcial (sem warning) ──
eq('edgeToCSS([])', S::edgeToCSS([]), '0px 0px 0px 0px');
eq('edgeToCSS partial', S::edgeToCSS(['top' => 8]), '8px 0px 0px 0px');
eq('cornersToCSS([])', S::cornersToCSS([]), '0px 0px 0px 0px');
eq('borderSide width sem color', S::borderSideToCSS(['width' => 2, 'style' => 'solid']), '2px solid #000000');
eq('gradient sem angle', S::gradientCSS(['type' => 'gradient', 'stops' => [['color' => '#f00', 'position' => 0]]]), 'linear-gradient(0deg, #f00 0%)');

// ── EQUIVALÊNCIA: esparso == completo (o critério de aceite) ──
$sparse = ['padding' => ['top' => 8], 'background' => ['type' => 'solid', 'color' => '#101a30']];
$expanded = [
    'padding' => ['top' => 8, 'right' => 0, 'bottom' => 0, 'left' => 0],
    'margin' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
    'border' => [
        'top' => ['width' => 0, 'style' => 'none', 'color' => '#000000'],
        'right' => ['width' => 0, 'style' => 'none', 'color' => '#000000'],
        'bottom' => ['width' => 0, 'style' => 'none', 'color' => '#000000'],
        'left' => ['width' => 0, 'style' => 'none', 'color' => '#000000'],
    ],
    'borderRadius' => ['topLeft' => 0, 'topRight' => 0, 'bottomRight' => 0, 'bottomLeft' => 0],
    'background' => ['type' => 'solid', 'color' => '#101a30'],
    'shadow' => ['enabled' => false, 'offsetX' => 0, 'offsetY' => 2, 'blur' => 8, 'spread' => 0, 'color' => 'rgba(0,0,0,0.15)'],
    'opacity' => 1,
];
eq('blockStyles(sparse) == blockStyles(full)', S::blockStyles($sparse), S::blockStyles($expanded));
check('blockStyles(sparse) tem padding:8px 0px 0px 0px', str_contains(S::blockStyles($sparse), 'padding:8px 0px 0px 0px;'));

// Genéricos determinísticos (espelho 1:1 de fonts.ts do client): cursive/fantasy
// ganham a concreta do container + o aproximado web à frente; sem duplicar.
check('cursive → Comic Sans MS + Comic Neue na frente',
    S::normalizeFontFamily('Brush Script MT, cursive') === "'Brush Script MT', 'Comic Sans MS', 'Comic Neue', cursive");
check('fantasy → Impact + Anton na frente',
    S::normalizeFontFamily('fantasy') === 'Impact, Anton, fantasy');
check('não duplica quando a concreta já está na pilha',
    S::normalizeFontFamily("'Comic Sans MS', cursive") === "'Comic Sans MS', 'Comic Neue', cursive");
check('pilha sem genérico passa intacta',
    S::normalizeFontFamily('Spectral, serif') === 'Spectral, serif');

echo "\n" . ($failures === 0 ? "TODOS OS TESTES PASSARAM\n" : "$failures FALHA(S)\n");
exit($failures === 0 ? 0 : 1);
