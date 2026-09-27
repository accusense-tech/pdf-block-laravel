<?php

declare(strict_types=1);

// Teste standalone do ImageAdjust (recorte/cor/zoom de imagem). O que importa
// aqui é a PARIDADE com core/imageAdjust.ts: as strings de CSS têm de sair
// idênticas, senão o PDF não bate com o canvas. Rodar via docker (sem Laravel).

require __DIR__ . '/../src/ImageAdjust.php';

use PdfBlock\Laravel\ImageAdjust;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (! $cond) { $failures++; }
}

// ─── Forma ────────────────────────────────────────────────────
check('sem ajuste não recorta', ImageAdjust::clipPath(null) === '' && ImageAdjust::clipPath([]) === '');
check('shape none não recorta', ImageAdjust::clipPath(['shape' => 'none']) === '');

check('arco 100 = meia-lua na esquerda',
    ImageAdjust::clipPath(['shape' => 'arch', 'shapeAmount' => 100]) === 'ellipse(100% 100% at 0% 50%)');
check('arco espelhado ancora na direita',
    ImageAdjust::clipPath(['shape' => 'arch', 'shapeAmount' => 100, 'shapeFlip' => true]) === 'ellipse(100% 100% at 100% 50%)');
check('arco 0 = curva suave (360%)',
    ImageAdjust::clipPath(['shape' => 'arch', 'shapeAmount' => 0]) === 'ellipse(100% 360% at 0% 50%)');
check('intensidade ausente = 50',
    ImageAdjust::clipPath(['shape' => 'arch']) === ImageAdjust::clipPath(['shape' => 'arch', 'shapeAmount' => 50]));
check('círculo 100 = círculo inscrito',
    ImageAdjust::clipPath(['shape' => 'circle', 'shapeAmount' => 100]) === 'circle(50.00% at 50% 50%)');
check('cortes retos são polígonos',
    str_starts_with(ImageAdjust::clipPath(['shape' => 'diagonal', 'shapeAmount' => 50]), 'polygon(')
    && str_starts_with(ImageAdjust::clipPath(['shape' => 'slant', 'shapeAmount' => 50]), 'polygon(')
    && str_starts_with(ImageAdjust::clipPath(['shape' => 'chevron', 'shapeAmount' => 50]), 'polygon('));
check('intensidade fora da faixa é limitada',
    ImageAdjust::clipPath(['shape' => 'arch', 'shapeAmount' => 999]) === ImageAdjust::clipPath(['shape' => 'arch', 'shapeAmount' => 100]));

// ─── Cor / foco / zoom ────────────────────────────────────────
check('valores padrão não viram filter',
    ImageAdjust::filter(['brightness' => 100, 'contrast' => 100, 'saturate' => 100, 'grayscale' => 0, 'blur' => 0]) === '');
check('filter na ordem esperada',
    ImageAdjust::filter(['brightness' => 120, 'grayscale' => 40]) === 'brightness(120%) grayscale(40%)');
check('foco completa o eixo ausente com o centro',
    ImageAdjust::focus(['focusX' => 20]) === '20% 50%' && ImageAdjust::focus(['focusY' => 80]) === '50% 80%');
check('recorte livre precisa de 3+ pontos',
    ImageAdjust::polygon(null) === '' && ImageAdjust::polygon([[0, 0], [100, 0]]) === ''
    && ImageAdjust::polygon([[0, 0], [100, 0], [50, 100]]) === 'polygon(0.00% 0.00%, 100.00% 0.00%, 50.00% 100.00%)');
check('shape custom usa os pontos',
    ImageAdjust::clipPath(['shape' => 'custom', 'points' => [[0, 0], [100, 0], [50, 100]]]) === 'polygon(0.00% 0.00%, 100.00% 0.00%, 50.00% 100.00%)'
    && ImageAdjust::clipPath(['shape' => 'custom']) === '');
check('arredondar os cantos gera mais pontos (e tira as pontas)',
    (function () {
        $tri = [[0, 0], [100, 0], [50, 100]];
        $reto = ImageAdjust::polygon($tri, 0);
        $curvo = ImageAdjust::polygon($tri, 80);
        return substr_count($reto, ',') === 2
            && substr_count($curvo, ',') > substr_count($reto, ',')
            && str_starts_with($reto, 'polygon(0.00% 0.00%')
            && ! str_starts_with($curvo, 'polygon(0.00% 0.00%');
    })());
check('raio POR CANTO: só o ponto marcado arredonda',
    (function () {
        // Quadrado com o 1º canto arredondado e os outros retos.
        $um = ImageAdjust::polygon([[0, 0, 80], [100, 0], [100, 100], [0, 100]], 0);
        $nenhum = ImageAdjust::polygon([[0, 0], [100, 0], [100, 100], [0, 100]], 0);
        // 3 cantos retos (1 ponto cada) + 1 arredondado (7 amostras) = 10 pontos.
        return substr_count($nenhum, ',') === 3 && substr_count($um, ',') === 9
            && ! str_starts_with($um, 'polygon(0.00% 0.00%');
    })());
check('o raio do ponto vence o geral',
    ImageAdjust::polygon([[0, 0, 0], [100, 0], [100, 100], [0, 100]], 100)
    !== ImageAdjust::polygon([[0, 0], [100, 0], [100, 100], [0, 100]], 100));
check('coordenadas fora da caixa são limitadas',
    ImageAdjust::polygon([[-20, 0], [400, 0], [50, 100]]) === 'polygon(0.00% 0.00%, 100.00% 0.00%, 50.00% 100.00%)');

// ─── CSS completo (a string que vai para o blade) ─────────────
check('css inline = clip + filter',
    ImageAdjust::css(['shape' => 'circle', 'shapeAmount' => 100, 'grayscale' => 50])
    === 'clip-path:circle(50.00% at 50% 50%);filter:grayscale(50%);');
check('zoom ancora no FOCO (não no centro)',
    ImageAdjust::css(['zoom' => 150, 'focusX' => 20, 'focusY' => 30])
    === 'transform:scale(1.5);transform-origin:20% 30%;'
    && ImageAdjust::css(['zoom' => 150]) === 'transform:scale(1.5);transform-origin:center;'
    && ImageAdjust::css(['zoom' => 100]) === '');
check('isActive só com ajuste de verdade',
    ! ImageAdjust::isActive(null) && ! ImageAdjust::isActive(['shape' => 'none'])
    && ImageAdjust::isActive(['shape' => 'arch']) && ImageAdjust::isActive(['focusX' => 10]));

echo "\n" . ($failures === 0 ? 'TODOS OS TESTES PASSARAM' : "{$failures} FALHA(S)") . "\n";
exit($failures === 0 ? 0 : 1);
