<?php

namespace PdfBlock\Laravel;

/**
 * Acesso ao núcleo COMPARTILHADO do paginador (clip-window).
 *
 * A fonte única vive em packages/schema/paginator/paginator-core.js e é copiada
 * para resources/js/paginator-core.js por `pnpm gen`. O document.blade.php
 * injeta o conteúdo cru num <script> — mesmas funções que o client testa
 * (packages/react/src/pagination/serverPaginatorCore.test.ts).
 */
class PaginatorScript
{
    public static function core(): string
    {
        $path = __DIR__ . '/../resources/js/paginator-core.js';
        $js = @file_get_contents($path);

        return $js === false ? '' : $js;
    }
}
