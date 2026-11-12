<?php

declare(strict_types=1);

// Fundo da PÁGINA/CONTEÚDO com gradiente: StyleHelpers::pageBackgroundCSS
// (o `background` saneado que o document.blade e o @page nomeado emitem) e
// StyleHelpers::pageBackgroundSolid (a queda para cor sólida do e-mail).
// Os textos esperados do gradiente são os MESMOS do teste TS
// (`utils.pageBackground.test.ts`) — canvas e PDF escrevem igual.
// docker run --rm -v $PWD:/app -w /app php:8.3-cli php packages/laravel/tests/page_background_check.php

require __DIR__ . '/../src/CssSanitizer.php';
require __DIR__ . '/../src/StyleHelpers.php';

// `gradientCSS` usa `collect()` (Laravel); fora do framework, um substituto mínimo.
if (! function_exists('collect')) {
    function collect(array $items): object
    {
        return new class ($items) {
            public function __construct(private array $items) {}
            public function map(callable $fn): self { return new self(array_map($fn, $this->items)); }
            public function implode(string $glue): string { return implode($glue, $this->items); }
        };
    }
}

use PdfBlock\Laravel\StyleHelpers as S;

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

$linear = ['type' => 'gradient', 'gradientType' => 'linear', 'angle' => 135, 'stops' => [
    ['color' => '#0f172a', 'position' => 0], ['color' => '#334155', 'position' => 100],
]];

// ── string: o comportamento de sempre ──
check('string hexa passa', S::pageBackgroundCSS('#ffffff', '#ffffff'), '#ffffff');
check('string vazia → fallback', S::pageBackgroundCSS('', '#ffffff'), '#ffffff');
check('conteúdo vazio → vazio (herda a página)', S::pageBackgroundCSS('', ''), '');
check('string com injeção → fallback', S::pageBackgroundCSS('#fff;}body{display:none', '#ffffff'), '#ffffff');

// ── objeto ──
check('sólido em objeto → a cor', S::pageBackgroundCSS(['type' => 'solid', 'color' => '#123456'], '#ffffff'), '#123456');
check('gradiente linear', S::pageBackgroundCSS($linear, '#ffffff'), 'linear-gradient(135deg, #0f172a 0%, #334155 100%)');
check('gradiente radial', S::pageBackgroundCSS(['gradientType' => 'radial'] + $linear, '#ffffff'), 'radial-gradient(circle, #0f172a 0%, #334155 100%)');
check('gradiente sem tipo → linear', S::pageBackgroundCSS(['type' => 'gradient', 'angle' => 90, 'stops' => $linear['stops']], '#fff'), 'linear-gradient(90deg, #0f172a 0%, #334155 100%)');
check('ângulo e posição fracionários com a regra do cssNum', S::pageBackgroundCSS(['type' => 'gradient', 'gradientType' => 'linear', 'angle' => 33.333333, 'stops' => [['color' => '#000', 'position' => 12.25]]], '#fff'), 'linear-gradient(33.3deg, #000 12.3%)');
check('parada com injeção vira transparent', S::pageBackgroundCSS(['type' => 'gradient', 'gradientType' => 'linear', 'angle' => 90, 'stops' => [
    ['color' => 'red);}body{x:y', 'position' => 0], ['color' => '#fff', 'position' => 100],
]], '#ffffff'), 'linear-gradient(90deg, transparent 0%, #fff 100%)');
check('ângulo não numérico → 90', S::pageBackgroundCSS(['type' => 'gradient', 'gradientType' => 'linear', 'angle' => '1;x', 'stops' => $linear['stops']], '#fff'), 'linear-gradient(90deg, #0f172a 0%, #334155 100%)');
check('parada sem posição numérica é descartada', S::pageBackgroundCSS(['type' => 'gradient', 'gradientType' => 'linear', 'angle' => 0, 'stops' => [
    ['color' => '#111', 'position' => 'x'], ['color' => '#222', 'position' => 50],
]], '#fff'), 'linear-gradient(0deg, #222 50%)');
check('gradiente sem parada válida → fallback', S::pageBackgroundCSS(['type' => 'gradient', 'stops' => []], '#ffffff'), '#ffffff');
check('imagem não é fundo de página → fallback', S::pageBackgroundCSS(['type' => 'image', 'url' => 'https://x/y.png'], '#ffffff'), '#ffffff');
check('lixo → fallback', S::pageBackgroundCSS(42, '#ffffff'), '#ffffff');

// ── e-mail: sem gradiente, 1ª parada ──
check('e-mail: string intacta', S::pageBackgroundSolid('#f3f3f3', '#fff'), '#f3f3f3');
check('e-mail: gradiente → 1ª parada', S::pageBackgroundSolid($linear, '#fff'), '#0f172a');
check('e-mail: sólido em objeto → a cor', S::pageBackgroundSolid(['type' => 'solid', 'color' => '#abc'], '#fff'), '#abc');
check('e-mail: lixo → fallback', S::pageBackgroundSolid(null, '#fff'), '#fff');

// ── @page: o Chromium só pinta cor ──
check('@page com cor: o background de sempre', S::pageBackgroundAtPage('#fff', '#ffffff'), 'background: #fff;');
check('@page com gradiente: cor da 1ª parada + imagem', S::pageBackgroundAtPage($linear, '#ffffff'), 'background-color: #0f172a; background-image: linear-gradient(135deg, #0f172a 0%, #334155 100%);');
check('@page: 1ª parada inválida → fallback na cor', S::pageBackgroundAtPage(['type' => 'gradient', 'gradientType' => 'linear', 'angle' => 0, 'stops' => [['color' => 'x;y', 'position' => 0]]], '#ffffff'), 'background-color: #ffffff; background-image: linear-gradient(0deg, transparent 0%);');

// ── @page nomeado da seção (v3) ──
$css = S::namedPageCss([['id' => 'A', 'pageSetup' => ['pageBackground' => $linear]]], [], 'T', 'D', '#333');
// O Chromium só pinta COR no @page: a 1ª parada vai como `background-color`.
check('@page nomeada: cor da 1ª parada + o gradiente', str_contains($css, "@page pdfbsecA {\n      background-color: #0f172a; background-image: linear-gradient(135deg, #0f172a 0%, #334155 100%);"), true);
$cssHex = S::namedPageCss([['id' => 'A', 'pageSetup' => ['pageBackground' => '#0b1021']]], [], 'T', 'D', '#333');
check('@page nomeada com cor continua igual', str_contains($cssHex, 'background: #0b1021;'), true);
$cssEvil = S::namedPageCss([['id' => 'A', 'pageSetup' => ['pageBackground' => ['type' => 'gradient', 'gradientType' => 'linear', 'angle' => 0, 'stops' => [['color' => '#000;}@page{', 'position' => 0]]]]]], [], 'T', 'D', '#333');
check('@page nomeada saneia a parada', str_contains($cssEvil, '}@page{'), false);

if ($failures > 0) {
    echo "\n$failures falha(s)\n";
    exit(1);
}
echo "\ntodos ok\n";
