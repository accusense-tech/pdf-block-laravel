<?php

declare(strict_types=1);

// Trava de regressão: NENHUM blade pode dimensionar altura em unidades de
// VIEWPORT (`vh`/`dvh`/`svh`/`lvh`).
//
// Por quê: no print o viewport É a folha. No modo contínuo a folha tem a altura
// do documento inteiro, então um `max-height:100vh` some no PDF — mas continua
// valendo na MEDIÇÃO, que roda no viewport do driver (800px de altura). A imagem
// era medida truncada em 800px, crescia no print e empurrava uma 2ª página num
// documento que não deveria paginar. Com o motor 'js' o mesmo descompasso
// desalinha o corte das folhas, porque o paginador materializa a partir da tela.
//
// O teto de altura das imagens vem de `--pdfb-img-max-h`, definida no
// document.blade: altura da folha no paginado, `none` no contínuo.

$base = __DIR__ . '/../resources/views';
$failures = 0;

function stripComments(string $s): string
{
    $s = preg_replace('/\{\{--.*?--\}\}/s', '', $s);   // comentário Blade
    $s = preg_replace('#/\*.*?\*/#s', '', $s);         // comentário CSS / PHP de bloco
    $s = preg_replace('#^\s*//.*$#m', '', $s);         // comentário PHP de linha
    return (string) $s;
}

$blades = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
$scanned = 0;
foreach ($blades as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }
    $scanned++;
    $code = stripComments((string) file_get_contents($file->getPathname()));
    if (preg_match('/(?:max-|min-)?height\s*:\s*[^;\'"]*\d\s*(?:d|s|l)?vh\b/i', $code, $m)) {
        $failures++;
        $rel = str_replace($base . '/', '', $file->getPathname());
        echo "FAIL: {$rel} dimensiona altura em viewport ('{$m[0]}') — use mm/px ou --pdfb-img-max-h\n";
    }
}
echo "ok: {$scanned} blades sem altura em unidade de viewport\n";

// A ponta que substituiu o `100vh` precisa continuar de pé nos dois lados.
$doc = (string) file_get_contents($base . '/document.blade.php');
$img = (string) file_get_contents($base . '/blocks/image.blade.php');

foreach ([
    ['document.blade define --pdfb-img-max-h', $doc, '--pdfb-img-max-h:'],
    ['document.blade zera o teto no contínuo', stripComments($doc), "'none'"],
    ['image.blade consome a var', $img, 'var(--pdfb-img-max-h'],
] as [$name, $haystack, $needle]) {
    if (str_contains($haystack, $needle)) {
        echo "ok: {$name}\n";
    } else {
        $failures++;
        echo "FAIL: {$name} (não contém: {$needle})\n";
    }
}

echo $failures === 0 ? "\nTODOS OS CHECKS PASSARAM\n" : "\n{$failures} FALHA(S)\n";
exit($failures === 0 ? 0 : 1);
