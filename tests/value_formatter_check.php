<?php

declare(strict_types=1);

// Teste standalone do ValueFormatter (formatação por tipo) — paridade com
// packages/react/src/data/format.ts (ver format.test.ts no pacote React).
// docker run --rm -v $PWD:/app -w /app php:8.3-cli php packages/laravel/tests/value_formatter_check.php

require __DIR__ . '/../src/Data/ValueFormatter.php';

use PdfBlock\Laravel\Data\ValueFormatter as VF;

$failures = 0;
function check(string $name, mixed $got, mixed $expected): void
{
    global $failures;
    if ($got === $expected) {
        echo "ok: $name\n";
    } else {
        $failures++;
        echo "FAIL: $name\n  esperado: " . json_encode($expected) . "\n  obtido:   " . json_encode($got) . "\n";
    }
}

// scalarString / sem format
check('null → vazio', VF::format(null, null), '');
check('objeto → vazio', VF::format(['a' => 1], null), '');
check('escalar sem format', VF::format('abc', null), 'abc');
check('número sem format', VF::format(42, null), '42');

// number (pt-BR: milhar '.', decimal ',')
check('number:2', VF::format(1234.5, 'number:2'), '1.234,50');
check('number:0', VF::format(1234, 'number:0'), '1.234');
check('number negativo', VF::format(-1234.5, 'number:2'), '-1.234,50');
check('number string pt-BR', VF::format('1234,5', 'number:2'), '1.234,50');

// currency
check('currency BRL', VF::format(1234.5, 'currency:BRL'), 'R$ 1.234,50');
check('currency USD', VF::format(9.9, 'currency:USD'), '$ 9,90');
check('currency default', VF::format(5, 'currency'), 'R$ 5,00');

// percent
check('percent:1', VF::format(12.3, 'percent:1'), '12,3%');

// date / datetime (UTC)
check('date dd/MM/yyyy', VF::format('2026-06-13', 'date:dd/MM/yyyy'), '13/06/2026');
check('date yyyy-MM-dd', VF::format('2026-06-13', 'date:yyyy-MM-dd'), '2026-06-13');
check('datetime Z', VF::format('2026-06-13T08:05:00Z', 'datetime:dd/MM/yyyy HH:mm'), '13/06/2026 08:05');
check('date inválida → escalar', VF::format('não-data', 'date'), 'não-data');
check('date extenso d de MMMM', VF::format('2026-03-02', "date:d 'de' MMMM 'de' yyyy"), '2 de março de 2026');
check('date MMMM yyyy', VF::format('2026-03-02', 'date:MMMM yyyy'), 'março 2026');
check('date MMM abreviado', VF::format('2026-11-09', 'date:dd MMM yyyy'), '09 nov 2026');
check('literal entre aspas', VF::format('2026-03-02', "date:'dia' d"), 'dia 2');

// formatMap (path → format) a partir das variáveis do documento
require __DIR__ . '/../src/Data/DocumentVariables.php';
use PdfBlock\Laravel\Data\DocumentVariables as DV;

$doc = ['variables' => [
    ['key' => 'emissao', 'label' => 'Emissão', 'type' => 'date', 'format' => 'date:dd/MM/yyyy', 'value' => '2026-06-13'],
    ['key' => 'nome', 'label' => 'Nome', 'value' => 'Ana'],
    ['key' => 'itens', 'label' => 'Itens', 'type' => 'list', 'itemFields' => [
        ['key' => 'preco', 'label' => 'Preço', 'format' => 'currency:BRL'],
        ['key' => 'descricao', 'label' => 'Descrição'],
    ], 'value' => [['__id' => 'a', 'preco' => 10]]],
]];
$fm = DV::formatMap($doc);
check('formatMap: variável escalar', $fm['emissao'] ?? null, 'date:dd/MM/yyyy');
check('formatMap: campo de item (relativo)', $fm['preco'] ?? null, 'currency:BRL');
check('formatMap: default sys.hoje', $fm['sys.hoje'] ?? null, 'date:dd/MM/yyyy');
check('formatMap: default sys.agora', $fm['sys.agora'] ?? null, 'datetime:dd/MM/yyyy HH:mm');

// values + computed + buildData (overrides vencem)
$vals = DV::values($doc);
check('values: escalar', $vals['nome'] ?? null, 'Ana');
check('values: lista presente', is_array($vals['itens'] ?? null), true);
$now = new DateTimeImmutable('2026-07-06 14:30:45');
$sys = DV::computed($now)['sys'];
check('computed: sys.hoje parede local', $sys['hoje'], '2026-07-06');
check('computed: sys.agora parede local + Z literal', $sys['agora'], '2026-07-06T14:30:45Z');
$all = DV::buildData($doc, ['nome' => 'Runtime'], $now);
check('buildData: override do host vence', $all['nome'], 'Runtime');
check('buildData: sys presente', $all['sys']['hoje'] ?? null, '2026-07-06');
// Paridade do formato aplicado às automáticas (mesma string dos dois lados):
check('sys.agora formata como hora local', VF::format($sys['agora'], 'datetime:dd/MM/yyyy HH:mm'), '06/07/2026 14:30');

echo $failures === 0 ? "\nTODOS OS TESTES PASSARAM\n" : "\n$failures TESTE(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
