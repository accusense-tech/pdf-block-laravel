<?php

declare(strict_types=1);

// ESPELHO de `src/styles/cssNum.test.ts` (React). As duas implementações têm de
// produzir o MESMO texto para o mesmo float — senão o gradiente sai com ângulo
// diferente no canvas e no PDF, sem erro em lugar nenhum.
//
// A paridade sobre estilos INTEIROS é provada pelo golden (style-golden.json,
// fixtures `hz-precision-17` e `hz-huge-number`). Aqui ficam as REGRAS: meio
// para longe do zero, nada de notação científica, zeros à direita podados.
//
// Rodar via docker php:8.3-cli (ou pelo ChecksTest).

set_error_handler(function ($severity, $message) {
    throw new \ErrorException($message, 0, $severity);
});

require __DIR__ . '/../src/StyleHelpers.php';

use PdfBlock\Laravel\StyleHelpers as S;

$failures = 0;
function eq(string $name, $a, $b): void
{
    global $failures;
    $ok = $a === $b;
    echo ($ok ? 'ok: ' : 'FAIL: ') . $name
        . ($ok ? '' : '  (got=' . var_export($a, true) . ' exp=' . var_export($b, true) . ')') . "\n";
    if (! $ok) { $failures++; }
}

// ── Medida: UMA casa decimal ──
eq('33.333333333333336 → 33.3', S::cssNum(33.333333333333336), '33.3');
eq('66.66666666666667 → 66.7', S::cssNum(66.66666666666667), '66.7');
eq('0.1+0.2 → 0.3', S::cssNum(0.1 + 0.2), '0.3');
eq('2.25 → 2.3', S::cssNum(2.25), '2.3');

// ── Zeros à direita podados ──
eq('10 → 10', S::cssNum(10), '10');
eq('10.04 → 10', S::cssNum(10.04), '10');
eq('0 → 0', S::cssNum(0), '0');

// ── Meio para LONGE do zero, nos dois sinais ──
eq('0.25 → 0.3', S::cssNum(0.25), '0.3');
eq('-0.25 → -0.3', S::cssNum(-0.25), '-0.3');
eq('-8 → -8', S::cssNum(-8), '-8');

// ── Sem notação científica (`1.0E+21px` não é comprimento CSS) ──
eq('1e21 sem expoente', S::cssNum(1e21), '1000000000000000000000');

// ── Não-finito não vaza para a CSS ──
eq('NAN → 0', S::cssNum(NAN), '0');
eq('INF → 0', S::cssNum(INF), '0');

// ── Razão (opacidade): mais casas — 0,15 não pode virar 0,2 ──
eq('0.15 (3 casas)', S::cssNum(0.15, 3), '0.15');
eq('0.075 (3 casas)', S::cssNum(0.075, 3), '0.075');
eq('0.30000000000000004 (3 casas)', S::cssNum(0.30000000000000004, 3), '0.3');
eq('1 (3 casas)', S::cssNum(1, 3), '1');

echo "\n" . ($failures === 0 ? "TODOS OS TESTES PASSARAM\n" : "$failures FALHA(S)\n");
exit($failures === 0 ? 0 : 1);
