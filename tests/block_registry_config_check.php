<?php

declare(strict_types=1);

// Teste standalone do registro de blocos BUILT-IN a partir de config('pdf-block.blocks')
// e da precedência de plugin (app vence built-in em QUALQUER ordem de boot). Espelha o
// loop do PdfBlockServiceProvider::boot() sem bootar o Laravel. Rodar via docker php:8.3-cli.

// Stub do helper `env()` para conseguir `require` o arquivo de config isolado.
if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed { return $default; }
}

$base = __DIR__ . '/..';
require $base . '/src/Contracts/BlockRenderer.php';
require $base . '/src/BlockRendererRegistry.php';
require $base . '/src/BladeBlockRenderer.php';

use PdfBlock\Laravel\BladeBlockRenderer;
use PdfBlock\Laravel\BlockRendererRegistry;
use PdfBlock\Laravel\Contracts\BlockRenderer;

$config = require $base . '/config/pdf-block.php';
$blocks = $config['blocks'] ?? [];

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    if ($cond) {
        echo "ok: $name\n";
    } else {
        $failures++;
        echo "FAIL: $name\n";
    }
}

/** Réplica EXATA do loop de registro do PdfBlockServiceProvider::boot(). */
function registerBuiltins(BlockRendererRegistry $r, array $blocks): void
{
    foreach ($blocks as $mode => $map) {
        foreach ((array) $map as $type => $entry) {
            if ($r->has($mode, (string) $type)) {
                continue;
            }
            [$view, $as] = is_array($entry) ? [$entry['view'], $entry['as'] ?? 'block'] : [$entry, 'block'];
            $r->register($mode, (string) $type, new BladeBlockRenderer($view, $as));
        }
    }
}

/** Lê uma propriedade private readonly do BladeBlockRenderer (só p/ asserção do teste). */
function prop(BladeBlockRenderer $b, string $name): mixed
{
    $rp = new ReflectionProperty(BladeBlockRenderer::class, $name);
    return $rp->getValue($b);
}

// Renderer de app fake (identidade rastreável).
$fake = new class implements BlockRenderer {
    public function render(array $block, array $context = []): string { return 'FAKE'; }
};

// 1) O mapa não está vazio — só isso, aqui.
//
// Havia duas listas de tipos ESCRITAS À MÃO neste ponto (`$expectedPdf` e
// `$expectedEmail`) que conferiam "o config cobre cada um destes". Elas saíram:
// protegiam os blocos existentes de sumirem do config e não cobravam o PRÓXIMO,
// porque um bloco novo não entra numa lista manual sozinho — e quem esquece a
// linha do config é exatamente quem esqueceria a linha da lista.
//
// Quem faz essa conferência agora é `packages/react/src/blocks/registryConfig.test.ts`,
// que deriva os tipos de `CORE_DEFS` (a fonte real: um manifesto novo entra ao
// ser importado no barril), confere os dois modos contra `supportedModes`,
// e ainda checa que toda view registrada existe no disco. Roda no CI de PR, que
// é onde quem escreve o bloco está olhando.
//
// A conferência aqui ficou sendo a que o TS não alcança: a RESOLUÇÃO do mapa
// para renderer, com view/variável corretas, e a precedência de plugin. Duas
// listas da mesma coisa é como esta dívida começou.
check('config declara o mapa pdf', ! empty($blocks['pdf']));
check('config declara o mapa email', ! empty($blocks['email']));

// 2) Built-ins resolvem para BladeBlockRenderer com view/variável corretas.
$r = new BlockRendererRegistry();
registerBuiltins($r, $blocks);
$text = $r->for('pdf', 'text');
check('pdf.text → BladeBlockRenderer', $text instanceof BladeBlockRenderer);
check('pdf.text view correta', $text instanceof BladeBlockRenderer && prop($text, 'view') === 'pdf-block::blocks.text');
check("pdf.text var 'block'", $text instanceof BladeBlockRenderer && prop($text, 'as') === 'block');
$struct = $r->for('pdf', 'structure');
check('pdf.structure view correta', $struct instanceof BladeBlockRenderer && prop($struct, 'view') === 'pdf-block::structure');
check("pdf.structure var 'structure' (render recursivo)", $struct instanceof BladeBlockRenderer && prop($struct, 'as') === 'structure');
$enoop = $r->for('email', 'chart');
check('email.chart → noop view', $enoop instanceof BladeBlockRenderer && prop($enoop, 'view') === 'pdf-block::email.blocks.noop');
$eqr = $r->for('email', 'qrcode');
check('email.qrcode reaproveita a view de imagem', $eqr instanceof BladeBlockRenderer && prop($eqr, 'view') === 'pdf-block::email.blocks.image');

// 3) Precedência — app registra ANTES do provider: built-in NÃO sobrescreve.
$rBefore = new BlockRendererRegistry();
$rBefore->register('pdf', 'text', $fake);
registerBuiltins($rBefore, $blocks);
check('app-antes vence o built-in', $rBefore->for('pdf', 'text') === $fake);

// 4) Precedência — app registra DEPOIS do provider: register() sobrescreve.
$rAfter = new BlockRendererRegistry();
registerBuiltins($rAfter, $blocks);
$rAfter->register('pdf', 'text', $fake);
check('app-depois vence o built-in', $rAfter->for('pdf', 'text') === $fake);

// 5) Plugin de tipo não-built-in coexiste com os built-ins.
$rPlugin = new BlockRendererRegistry();
$rPlugin->register('pdf', 'playground.news', $fake);
registerBuiltins($rPlugin, $blocks);
check('plugin não-built-in preservado', $rPlugin->for('pdf', 'playground.news') === $fake);
check('built-in registrado ao lado do plugin', $rPlugin->for('pdf', 'image') instanceof BladeBlockRenderer);

// 6) Tipo desconhecido → null (o dispatcher cai no comentário de fallback).
check('tipo desconhecido → null', $rPlugin->for('pdf', 'inexistente') === null);

echo $failures ? "\n$failures FALHA(S)\n" : "\nTODOS OK\n";
exit($failures ? 1 : 0);
