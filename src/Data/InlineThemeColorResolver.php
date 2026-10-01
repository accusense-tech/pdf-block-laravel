<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Data;

/**
 * Re-veste as cores de texto INLINE dos blocos rich-text (TipTap): para toda
 * marca `textStyle` com `attrs.themeColor` (o NOME de uma cor de tema), reescreve
 * `attrs.color` (o hex) com o valor da paleta ativa (`document['theme']['colors']`).
 *
 * Espelha `reprojectInlineThemeColors` do client (`packages/react/src/core/themes.ts`).
 * Roda DEPOIS do ThemeResolver (que popula `document['theme']`) e ANTES do
 * TiptapConverter — assim o `<span style="color:...">` do PDF re-veste ao trocar o
 * tema ativo, mesmo que o `color` armazenado esteja defasado. Agnóstico de
 * profundidade (percorre sections→flow→content→content→marks recursivamente).
 */
class InlineThemeColorResolver
{
    public static function resolve(array $document): array
    {
        $palette = $document['theme']['colors'] ?? null;
        if (! is_array($palette) || $palette === []) {
            return $document;
        }

        return self::walk($document, $palette);
    }

    private static function walk(mixed $node, array $palette): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        // Marca `textStyle` OU `link` com `themeColor` → reescreve `color` da paleta.
        $__t = $node['type'] ?? null;
        if (($__t === 'textStyle' || $__t === 'link') && isset($node['attrs']) && is_array($node['attrs'])) {
            $name = $node['attrs']['themeColor'] ?? null;
            if (is_string($name) && isset($palette[$name]) && is_string($palette[$name])) {
                $node['attrs']['color'] = $palette[$name];
            }
        }

        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = self::walk($value, $palette);
        }

        return $out;
    }
}
