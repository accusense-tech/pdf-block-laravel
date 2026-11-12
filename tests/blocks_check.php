<?php

declare(strict_types=1);

// Teste standalone dos renderers de bloco do servidor (NewsCard + Repeater),
// sem bootar o Laravel inteiro. Rodar via docker php:8.3-cli.

// Stub do helper `e()` do Laravel (escape HTML).
if (! function_exists('e')) {
    function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

$base = __DIR__ . '/..';
require $base . '/src/Contracts/BlockRenderer.php';
require $base . '/src/Data/ValueFormatter.php';
require $base . '/src/Data/BindingResolver.php';
require $base . '/src/BlockRendererRegistry.php';
require $base . '/src/Blocks/RepeaterRenderer.php';
require __DIR__ . '/../../../apps/laravel-sandbox/app/Blocks/NewsCardRenderer.php';

use App\Blocks\NewsCardRenderer;
use PdfBlock\Laravel\BlockRendererRegistry;
use PdfBlock\Laravel\Blocks\RepeaterRenderer;

$failures = 0;
function assertContains(string $name, string $haystack, string $needle): void
{
    global $failures;
    if (str_contains($haystack, $needle)) {
        echo "ok: $name\n";
    } else {
        $failures++;
        echo "FAIL: $name (não contém: $needle)\n";
    }
}

// 1. NewsCardRenderer isolado
$news = new NewsCardRenderer();
$cardHtml = $news->render(['props' => [
    'title' => 'Manchete <X>',
    'description' => 'Resumo da notícia',
    'link' => 'https://ex.com/a',
]]);
assertContains('newsCard título (escapado)', $cardHtml, 'Manchete &lt;X&gt;');
assertContains('newsCard descrição', $cardHtml, 'Resumo da notícia');
assertContains('newsCard link', $cardHtml, 'https://ex.com/a');

// 2. RepeaterRenderer → 1 item por registro da fonte
$registry = new BlockRendererRegistry();
$registry->register('pdf', 'playground.news', new NewsCardRenderer());
$repeater = new RepeaterRenderer($registry);

$data = ['news-mock' => [
    ['title' => 'Primeira', 'description' => 'd1', 'link' => '#'],
    ['title' => 'Segunda',  'description' => 'd2', 'link' => '#'],
    ['title' => 'Terceira', 'description' => 'd3', 'link' => '#'],
]];
$repHtml = $repeater->render(
    ['type' => 'core.repeater', 'props' => ['sourceId' => 'news-mock', 'itemType' => 'playground.news', 'gap' => 12]],
    ['mode' => 'pdf', 'data' => $data],
);
assertContains('repeater item 1', $repHtml, 'Primeira');
assertContains('repeater item 2', $repHtml, 'Segunda');
assertContains('repeater item 3', $repHtml, 'Terceira');
assertContains('repeater gap', $repHtml, 'margin-bottom:12px');

// 3. Repeater sem fonte/itemType → vazio
$empty = $repeater->render(['type' => 'core.repeater', 'props' => []], ['mode' => 'pdf', 'data' => $data]);
echo ($empty === '' ? "ok: repeater vazio sem config\n" : "FAIL: repeater deveria ser vazio\n");
if ($empty !== '') $failures++;

echo $failures === 0 ? "\nTODOS OS TESTES PASSARAM\n" : "\n$failures TESTE(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
