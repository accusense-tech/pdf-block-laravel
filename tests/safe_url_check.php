<?php

declare(strict_types=1);

// Teste standalone do StyleHelpers::safeUrl (sanitização de esquema de URL).
// docker run --rm -v $PWD:/app -w /app php:8.3-cli php packages/laravel/tests/safe_url_check.php

require __DIR__ . '/../src/StyleHelpers.php';

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

// Esquemas seguros / relativos passam
check('http passa', S::safeUrl('http://ex.com/a'), 'http://ex.com/a');
check('https passa', S::safeUrl('https://ex.com/a?b=1'), 'https://ex.com/a?b=1');
check('relativo passa', S::safeUrl('/pagina'), '/pagina');
check('âncora passa', S::safeUrl('#sec'), '#sec');
check('mailto passa', S::safeUrl('mailto:a@b.com'), 'mailto:a@b.com');

// Esquemas perigosos → '#'
check('javascript bloqueado', S::safeUrl('javascript:alert(1)'), '#');
check('javascript com espaços/controle', S::safeUrl("java\tscript:alert(1)"), '#');
check('JAVASCRIPT maiúsculo', S::safeUrl('JavaScript:alert(1)'), '#');
check('vbscript bloqueado', S::safeUrl('vbscript:msgbox(1)'), '#');

// data: — só imagem quando permitido
check('data:image bloqueado p/ link', S::safeUrl('data:image/png;base64,AAA'), '#');
check('data:image permitido p/ img', S::safeUrl('data:image/png;base64,AAA', true), 'data:image/png;base64,AAA');
check('data:text bloqueado mesmo em img', S::safeUrl('data:text/html,<script>', true), '#');

// vazio → ''
check('vazio', S::safeUrl(''), '');

echo $failures === 0 ? "\nTODOS OS TESTES PASSARAM\n" : "\n$failures TESTE(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
