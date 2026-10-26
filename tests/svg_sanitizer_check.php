<?php

declare(strict_types=1);

// Teste standalone do SvgSanitizer (defesa em profundidade do bloco `svg`).
// Garante que vetores de execução são removidos e o desenho vetorial preservado.
// Rodar via docker: php packages/laravel/tests/svg_sanitizer_check.php

require __DIR__ . '/../src/SvgSanitizer.php';

use PdfBlock\Laravel\SvgSanitizer;

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (! $cond) { $failures++; }
}

// Remove <script> com conteúdo.
$out = SvgSanitizer::sanitize('<svg><script>alert(1)</script><rect width="10" height="10"/></svg>');
check('remove <script>', strpos($out, '<script') === false && strpos($out, 'alert(1)') === false);
check('preserva <rect> após remover script', strpos($out, '<rect') !== false);

// Remove <foreignObject> (embute HTML/JS).
$out = SvgSanitizer::sanitize('<svg><foreignObject><body><img src=x onerror=alert(1)></body></foreignObject><path d="M0 0"/></svg>');
check('remove <foreignObject>', stripos($out, 'foreignObject') === false && stripos($out, 'onerror') === false);
check('preserva <path> após foreignObject', strpos($out, '<path') !== false);

// Remove handlers on*.
$out = SvgSanitizer::sanitize('<svg onload="alert(1)"><circle cx="5" cy="5" r="5" onclick=\'x()\'/></svg>');
check('remove onload', stripos($out, 'onload') === false);
check('remove onclick', stripos($out, 'onclick') === false);
check('preserva <circle>', strpos($out, '<circle') !== false);

// Neutraliza javascript: em href/xlink:href.
$out = SvgSanitizer::sanitize('<svg><a href="javascript:alert(1)"><text>x</text></a><a xlink:href="javascript:evil()">y</a></svg>');
check('neutraliza javascript: em href', stripos($out, 'javascript:') === false);

// Preserva gradiente/defs/use (desenho vetorial legítimo).
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"><stop offset="0%" stop-color="#5b8cff"/></linearGradient></defs><rect fill="url(#g)" width="100" height="20"/></svg>';
$out = SvgSanitizer::sanitize($svg);
check('preserva <linearGradient>', strpos($out, 'linearGradient') !== false);
check('preserva fill=url(#g)', strpos($out, 'url(#g)') !== false);

// Descarta conteúdo sem <svg> (evita HTML cru).
check('descarta HTML cru sem <svg>', SvgSanitizer::sanitize('<div onclick="x">não é svg</div>') === '');
check('descarta string vazia', SvgSanitizer::sanitize('   ') === '');

// Remove @import e expression() de <style> interno.
$out = SvgSanitizer::sanitize('<svg><style>@import url(http://evil);.a{width:expression(alert(1))}</style><rect/></svg>');
check('remove @import', stripos($out, '@import') === false);
check('remove expression(', stripos($out, 'expression(') === false);

echo "\n" . ($failures === 0 ? "TODOS OS TESTES PASSARAM\n" : "$failures FALHA(S)\n");
exit($failures === 0 ? 0 : 1);
