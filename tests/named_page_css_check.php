<?php

declare(strict_types=1);

// Teste standalone do P8/A2: Layouts de Página NOMEADOS por seção.
// StyleHelpers::resolvePageSetup (layoutRef → pageSetup efetivo) e
// StyleHelpers::namedPageCss (@page nome por seção + showOn).
// docker run --rm -v $PWD:/app -w /app php:8.3-cli php packages/laravel/tests/named_page_css_check.php

require __DIR__ . '/../src/CssSanitizer.php';
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

// ── pageName: sanitização estável ──
check('pageName simples', S::pageName('sec0'), 'pdfbsecsec0');
check('pageName sanitiza', S::pageName('doc::sec0'), 'pdfbsecdocsec0');
check('pageName vazio → 0', S::pageName('!!!'), 'pdfbsec0');

// ── resolvePageSetup: layout vence nos campos que define ──
$ps = ['paperSize' => ['preset' => 'a4'], 'orientation' => 'portrait', 'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20], 'header' => ['center' => 'ORIG']];
$layoutMap = ['lay1' => ['id' => 'lay1', 'name' => 'Capa', 'margins' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0], 'header' => ['center' => 'CAPA'], 'footer' => ['center' => 'Confid'], 'pageBackground' => '#0b1021']];
$eff = S::resolvePageSetup($ps, 'lay1', $layoutMap);
check('layout vence header', $eff['header']['center'], 'CAPA');
check('layout adiciona footer', $eff['footer']['center'], 'Confid');
check('layout vence margins', $eff['margins']['top'], 0);
check('layout adiciona pageBackground', $eff['pageBackground'], '#0b1021');
check('pageSetup preserva paperSize', $eff['paperSize']['preset'], 'a4');
check('pageSetup preserva orientation', $eff['orientation'], 'portrait');
check('layoutRef ausente → pageSetup intacto', S::resolvePageSetup($ps, null, $layoutMap), $ps);
check('layoutRef pendente → pageSetup intacto', S::resolvePageSetup($ps, 'nope', $layoutMap), $ps);

// ── namedPageCss: 1 seção, footer em todas as páginas ──
$secsAll = [['id' => 'A', 'pageSetup' => ['footer' => ['center' => 'Página {page} de {pages}']]]];
$cssAll = S::namedPageCss($secsAll, [], 'T', '01/02/2026', '#333');
check('all: @page nomeada da seção', str_contains($cssAll, '@page pdfbsecA {'), true);
check('all: footer no @page nomeado', str_contains($cssAll, '@bottom-center { content: "Página " counter(page) " de " counter(pages); font-size: 10px; color: #333; }'), true);
check('all: SEM @page nome:first (igual em todas)', str_contains($cssAll, 'pdfbsecA:first'), false);

// ── except-first (legado showOnFirstPage:false) na 1ª seção → reset na :first ──
$secsExcept = [['id' => 'A', 'pageSetup' => ['footer' => ['center' => 'rodapé', 'showOnFirstPage' => false]]]];
$cssExcept = S::namedPageCss($secsExcept, [], 'T', 'D', '#333');
check('except-first: footer no @page normal', str_contains($cssExcept, "@page pdfbsecA {\n      @bottom-center { content: \"rodapé\""), true);
check('except-first: reset content:none no :first', str_contains($cssExcept, "@page pdfbsecA:first {\n      @bottom-center { content: none; }"), true);

// ── first-only na 1ª seção → boxes SÓ na :first, base sem boxes ──
$secsFirst = [['id' => 'A', 'pageSetup' => ['header' => ['center' => 'CAPA', 'showOn' => 'first-only']]]];
$cssFirst = S::namedPageCss($secsFirst, [], 'T', 'D', '#333');
check('first-only: header na :first', str_contains($cssFirst, "@page pdfbsecA:first {\n      @top-center { content: \"CAPA\""), true);
check('first-only: SEM @page base com boxes', str_contains($cssFirst, "@page pdfbsecA {\n"), false);

// ── per-section distinto + last-only: 2 seções, footers diferentes ──
$secsTwo = [
    ['id' => 'A', 'pageSetup' => ['footer' => ['center' => 'CAP-1']]],
    ['id' => 'B', 'pageSetup' => ['footer' => ['center' => 'CAP-2']]],
];
$cssTwo = S::namedPageCss($secsTwo, [], 'T', 'D', '#333');
check('per-section: seção A tem CAP-1', str_contains($cssTwo, '@page pdfbsecA {') && str_contains($cssTwo, '"CAP-1"'), true);
check('per-section: seção B tem CAP-2', str_contains($cssTwo, '@page pdfbsecB {') && str_contains($cssTwo, '"CAP-2"'), true);

// last-only com 2 seções → só a ÚLTIMA (B) tem boxes
$secsLast = [
    ['id' => 'A', 'pageSetup' => ['footer' => ['center' => 'RODAPÉ', 'showOn' => 'last-only']]],
    ['id' => 'B', 'pageSetup' => ['footer' => ['center' => 'RODAPÉ', 'showOn' => 'last-only']]],
];
$cssLast = S::namedPageCss($secsLast, [], 'T', 'D', '#333');
check('last-only: última seção (B) com footer', str_contains($cssLast, '@page pdfbsecB {'), true);
check('last-only: 1ª seção (A) SEM @page (vazia)', str_contains($cssLast, '@page pdfbsecA'), false);

// layoutRef: seção referencia um PageLayout nomeado
$secsRef = [['id' => 'A', 'pageSetup' => [], 'layoutRef' => 'lay1']];
$cssRef = S::namedPageCss($secsRef, [['id' => 'lay1', 'name' => 'Capa', 'footer' => ['center' => 'DO-LAYOUT']]], 'T', 'D', '#333');
check('layoutRef: footer vem do PageLayout', str_contains($cssRef, '"DO-LAYOUT"'), true);

echo $failures === 0 ? "\nTODOS OS TESTES PASSARAM\n" : "\n$failures TESTE(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
