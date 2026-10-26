<?php

declare(strict_types=1);

// Parágrafo vazio × parágrafo com quebra de linha no PDF.
//
// O defeito: o Blade escondia `p:last-child:has(> br:only-child)` para sumir com o
// parágrafo vazio que o TipTap acrescenta depois de título. Só que `:only-child`
// ignora nó de texto, e `<p>Fulano<br>CONTRATADA</p>` — o último parágrafo de uma
// coluna de assinatura — também casava: o texto sumia do PDF sem erro nenhum.
// CSS não distingue "br sozinho" de "texto + br"; quem distingue é o conversor,
// que marca o vazio com `data-pdfb-empty`, e o CSS mira a marca.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/TextStyleAttributes.php';
require __DIR__ . '/../src/ColoredBlockquote.php';
require __DIR__ . '/../src/Xref.php';
require __DIR__ . '/../src/StyledLink.php';
require __DIR__ . '/../src/Variable.php';
require __DIR__ . '/../src/TiptapConverter.php';

use PdfBlock\Laravel\TiptapConverter;

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    if ($ok) { echo "ok: $name\n"; }
    else { $failures++; echo "FAIL: $name $extra\n"; }
}

$conv = new TiptapConverter();
$p = fn (array $content = []) => ['type' => 'paragraph'] + ($content ? ['content' => $content] : []);
$text = fn (string $t) => ['type' => 'text', 'text' => $t];
$br = ['type' => 'hardBreak'];

// ── 1. Texto com quebra NÃO é marcado como vazio ──
$html = $conv->toHtml(['type' => 'doc', 'content' => [$p([$text('Fulano'), $br, $text('CONTRATADA')])]]);
check('texto + <br> não leva a marca de vazio', !str_contains($html, 'data-pdfb-empty'), $html);
check('texto + <br> preserva o texto', str_contains($html, 'Fulano') && str_contains($html, 'CONTRATADA'), $html);

// ── 2. Parágrafo vazio é marcado ──
$html = $conv->toHtml(['type' => 'doc', 'content' => [$p([$text('a')]), $p()]]);
check('parágrafo vazio leva a marca', str_contains($html, '<p data-pdfb-empty><br></p>'), $html);

// ── 3. Parágrafo só com hardBreak também é vazio ──
$html = $conv->toHtml(['type' => 'doc', 'content' => [$p([$br])]]);
check('parágrafo só com <br> leva a marca', (bool) preg_match('~<p[^>]*data-pdfb-empty[^>]*><br\s*/?></p>~', $html), $html);

// ── 4. O CSS do documento não decide "vazio" por :only-child ──
$blade = file_get_contents(__DIR__ . '/../resources/views/document.blade.php');
check('Blade não usa br:only-child', !str_contains($blade, 'br:only-child'));
check('Blade esconde o vazio final pela marca', str_contains($blade, 'p[data-pdfb-empty]:last-child'));

exit($failures > 0 ? 1 : 0);
