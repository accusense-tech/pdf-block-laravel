<?php

declare(strict_types=1);

// Teste standalone do CssSanitizer (anti-injeção de CSS em <style>/@page).
// docker run --rm -v $PWD:/app -w /app php:8.3-cli php packages/laravel/tests/css_sanitizer_check.php

require __DIR__ . '/../src/CssSanitizer.php';

use PdfBlock\Laravel\CssSanitizer;

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    if ($ok) { echo "ok: $name\n"; }
    else { $failures++; echo "FAIL: $name $extra\n"; }
}
function eq(string $name, string $got, string $want): void
{
    check($name, $got === $want, "(got '$got' want '$want')");
}

// ── color: valores legítimos passam ──
eq('color hex #fff', CssSanitizer::color('#fff'), '#fff');
eq('color hex #ffffff', CssSanitizer::color('#ffffff'), '#ffffff');
eq('color hex 8-dígitos', CssSanitizer::color('#11223344'), '#11223344');
eq('color rgba', CssSanitizer::color('rgba(0, 0, 0, 0.15)'), 'rgba(0, 0, 0, 0.15)');
eq('color rgb moderno', CssSanitizer::color('rgb(255 0 0 / 50%)'), 'rgb(255 0 0 / 50%)');
eq('color hsl', CssSanitizer::color('hsl(120, 50%, 50%)'), 'hsl(120, 50%, 50%)');
eq('color keyword transparent', CssSanitizer::color('transparent'), 'transparent');
eq('color keyword currentColor', CssSanitizer::color('currentColor'), 'currentColor');
eq('color com espaços', CssSanitizer::color('  #abc  '), '#abc');

// ── color: injeção é bloqueada (cai no fallback) ──
eq('color injeção ; }', CssSanitizer::color('red; } body { display:none'), 'inherit');
eq('color injeção url()', CssSanitizer::color('url(javascript:alert(1))'), 'inherit');
eq('color injeção expression', CssSanitizer::color('#fff;background:url(x)'), 'inherit');
eq('color vazio → fallback', CssSanitizer::color('', '#000'), '#000');
eq('color fallback custom', CssSanitizer::color('nope}{', '#123456'), '#123456');
check('color sem chaves/;', ! str_contains(CssSanitizer::color('a{b}c;d'), '{'));

// ── length: número com/sem unidade ──
eq('length 20px', CssSanitizer::length('20px'), '20px');
eq('length -5mm', CssSanitizer::length('-5mm'), '-5mm');
eq('length 10%', CssSanitizer::length('10%'), '10%');
eq('length puro 10', CssSanitizer::length('10'), '10');
eq('length de int', CssSanitizer::length(42), '42');
eq('length injeção → fallback', CssSanitizer::length('10px; } x{y:z}'), '0');
eq('length unidade inválida → fallback', CssSanitizer::length('10foo'), '0');

// ── number: número puro (sem unidade) ──
eq('number 210', CssSanitizer::number('210'), '210');
eq('number 20.5', CssSanitizer::number('20.5'), '20.5');
eq('number -3', CssSanitizer::number(-3), '-3');
eq('number com unidade → fallback', CssSanitizer::number('10px'), '0');
eq('number injeção → fallback', CssSanitizer::number('1;}x{y'), '0');
eq('number fallback custom', CssSanitizer::number('abc', '99'), '99');

// ── fontFamily: nomes válidos passam; injeção cai no fallback ──
eq('font aspas simples', CssSanitizer::fontFamily("'Open Sans', sans-serif"), "'Open Sans', sans-serif");
eq('font simples', CssSanitizer::fontFamily('Roboto, sans-serif'), 'Roboto, sans-serif');
eq('font 1 palavra', CssSanitizer::fontFamily('Arial'), 'Arial');
eq('font injeção ; :', CssSanitizer::fontFamily('red;color:blue'), 'inherit');
eq('font injeção } {', CssSanitizer::fontFamily('Foo; } html{display:none}'), 'inherit');
eq('font vazio → fallback', CssSanitizer::fontFamily('', 'serif'), 'serif');

echo $failures === 0 ? "\nTODOS OS TESTES PASSARAM\n" : "\n$failures TESTE(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
