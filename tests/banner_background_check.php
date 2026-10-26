<?php

declare(strict_types=1);

// Fundo do BANNER: `background-size`/`background-repeat`. Espelho do teste TS
// `core/bannerBackground.test.ts` — os dois lados têm de produzir o MESMO CSS,
// senão o mosaico do editor não bate com o do PDF.
//
//   docker run --rm -v "$PWD":/repo -w /repo/packages/laravel php:8.4-cli \
//     php tests/banner_background_check.php

require __DIR__ . '/../src/StyleHelpers.php';

use PdfBlock\Laravel\StyleHelpers as S;

$failures = 0;

function eq(string $name, string $actual, string $expected): void
{
    global $failures;
    if ($actual === $expected) {
        echo "ok: $name\n";
        return;
    }
    $failures++;
    echo "FAIL: $name — esperado '$expected', obtido '$actual'\n";
}

// ── Mosaico: NUNCA o `auto` do CSS ──────────────────────────────────────────
// `auto` é o tamanho NATURAL da imagem: numa foto de 1200px+ um único ladrilho
// cobre o banner inteiro e nada se repete (o bug do "mosaico esticado").
eq('mosaico usa % da caixa', S::bannerBackgroundSize('auto', 25), '25% auto');
eq('mosaico 12%', S::bannerBackgroundSize('auto', 12), '12% auto');
eq('mosaico sem tamanho → default 25', S::bannerBackgroundSize('auto', null), '25% auto');
eq('mosaico com 0 → default', S::bannerBackgroundSize('auto', 0), '25% auto');
eq('mosaico negativo → default', S::bannerBackgroundSize('auto', -5), '25% auto');
eq('mosaico acima de 100 → teto', S::bannerBackgroundSize('auto', 320), '100% auto');
eq('mosaico com fração', S::bannerBackgroundSize('auto', 12.5), '12.5% auto');

// ── Cobrir/conter ignoram o ladrilho ────────────────────────────────────────
eq('cobrir', S::bannerBackgroundSize('cover', 12), 'cover');
eq('conter', S::bannerBackgroundSize('contain', 12), 'contain');
eq('ausente → cobrir', S::bannerBackgroundSize(null, 12), 'cover');

// ── Só o mosaico ladrilha ───────────────────────────────────────────────────
// Sem isto o CSS cai no default `repeat` e 'contain' vira mosaico no PDF.
eq('repeat só no mosaico', S::bannerBackgroundRepeat('auto'), 'repeat');
eq('conter não ladrilha', S::bannerBackgroundRepeat('contain'), 'no-repeat');
eq('cobrir não ladrilha', S::bannerBackgroundRepeat('cover'), 'no-repeat');
eq('ausente não ladrilha', S::bannerBackgroundRepeat(null), 'no-repeat');

echo $failures === 0 ? "\nTODOS OS CHECKS PASSARAM\n" : "\n$failures CHECK(S) FALHARAM\n";
exit($failures === 0 ? 0 : 1);
