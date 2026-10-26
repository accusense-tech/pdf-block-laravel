<?php

declare(strict_types=1);

// O gráfico centraliza com margem automática dos LADOS. O `margin:0 auto`
// escrito depois dos estilos do bloco apagava a margem de cima e de baixo, e o
// gráfico encostava no bloco seguinte no PDF (bateria do MCP de 22/out).
// Gêmeo de `react/src/blocks/renderers/chart.tsx` (`chart.test.tsx`).

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (! $cond) { $failures++; }
}

$view = (string) file_get_contents(__DIR__ . '/../resources/views/blocks/chart.blade.php');
check('chart não sobrescreve a margem do bloco com o atalho', ! preg_match('/margin:\s*0\s+auto/', $view));
check('chart centraliza só pelos lados', str_contains($view, 'margin-left:auto;margin-right:auto'));

exit($failures > 0 ? 1 : 0);
