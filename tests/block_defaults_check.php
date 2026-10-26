<?php

declare(strict_types=1);

// Teste standalone de BlockDefaults (defaults dos campos próprios por tipo).
// Garante (a) os valores canônicos (espelho de DEFAULT_OWN_FIELDS do React) e
// (b) que `fill` preenche ausentes sem sobrescrever explícitos. Rodar via docker.

require __DIR__ . '/../src/BlockDefaults.php';

use PdfBlock\Laravel\BlockDefaults;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (! $cond) { $failures++; }
}

// (a) Valores canônicos — comparados 1:1 com a FONTE ÚNICA no disco
// (packages/schema/defaults.json), a mesma origem de DEFAULT_OWN_FIELDS (TS) e
// de SchemaDefaults.gen.php (PHP). Sem cópia manual: se o JSON mudar e o artefato
// gerado não for regenerado (`pnpm gen`), este teste acusa a divergência.
$schemaPath = __DIR__ . '/../../schema/defaults.json';
$schema = json_decode((string) file_get_contents($schemaPath), true);
check('defaults.json legível', is_array($schema) && isset($schema['ownFields'], $schema['bannerFields']));
check('BlockDefaults::all() == defaults.json/ownFields (paridade c/ fonte única)', BlockDefaults::all() == $schema['ownFields']);
check('bannerFields == defaults.json/bannerFields', BlockDefaults::bannerFields() == $schema['bannerFields']);

// (b) fill preenche ausentes, preserva explícitos, no-op em plugin.
// Botão unificado: o visual vive em styles (fill não semeia styles) — os campos
// próprios do botão são só texto/link/fonte/alinhamento.
$btn = BlockDefaults::fill(['id' => 'b1', 'type' => 'button']);
check('fill button fontColor default (#ffffff)', ($btn['fontColor'] ?? null) === '#ffffff');
check('fill button fontWeight default 600', ($btn['fontWeight'] ?? null) === 600);
check('fill button SEM bgColor próprio (schema unificado)', ! array_key_exists('bgColor', $btn));
check('fill spacer height 24 (não 20)', (BlockDefaults::fill(['type' => 'spacer'])['height'] ?? null) === 24);
check('fill table headerRow true (não false)', (BlockDefaults::fill(['type' => 'table'])['headerRow'] ?? null) === true);
check('fill chart height 300 (não 250)', (BlockDefaults::fill(['type' => 'chart'])['height'] ?? null) === 300);

$override = BlockDefaults::fill(['type' => 'button', 'fontColor' => '#101a30', 'text' => 'Baixar']);
check('fill preserva override fontColor', ($override['fontColor'] ?? null) === '#101a30');
check('fill preserva override text', ($override['text'] ?? null) === 'Baixar');

$custom = ['id' => 'x', 'type' => 'core.repeater', 'props' => ['a' => 1]];
check('fill é no-op p/ tipo plugin', BlockDefaults::fill($custom) === $custom);

echo "\n" . ($failures === 0 ? "TODOS OS TESTES PASSARAM\n" : "$failures FALHA(S)\n");
exit($failures === 0 ? 0 : 1);
