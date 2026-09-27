<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

/**
 * Sanitização de valores CRUS antes de interpolá-los em CSS (`<style>`, `@page`,
 * atributos `style`). Os valores vêm da DSL do usuário final; sem sanitização,
 * um valor como `red; } body { display:none` interpolado num bloco de estilo
 * PERMITE injeção de CSS (fechar a regra e injetar outras). Cada método valida
 * contra uma whitelist e devolve um fallback seguro quando o valor não casa.
 *
 * IMPORTANTE quanto à ORDEM: os tokens de tema `{{token:...}}` são resolvidos
 * pelo `ThemeResolver` ANTES do render (ver PdfBlockRenderer::toHtml), então aqui
 * já chegam valores concretos (hex/rgb/...). Um token não resolvido cairia no
 * fallback — mas isso não deve ocorrer no pipeline normal.
 */
class CssSanitizer
{
    /**
     * Cor CSS: hex (#RGB/#RGBA/#RRGGBB/#RRGGBBAA), função rgb()/rgba()/hsl()/hsla()
     * ou palavra-chave simples (transparent, currentColor, inherit, white, ...).
     * Qualquer outra coisa (contendo `;`, `}`, `{`, `url(`, etc.) → `$fallback`.
     */
    public static function color(string $value, string $fallback = 'inherit'): string
    {
        $v = trim($value);
        if ($v === '') {
            return $fallback;
        }
        // #RGB, #RGBA, #RRGGBB, #RRGGBBAA
        if (preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) {
            return $v;
        }
        // rgb()/rgba()/hsl()/hsla() — só números, %, separadores e unidades (deg/turn).
        // Sem `;{}`: o char-set para no 1º `)` e `\)$` ancora o fechamento no fim.
        if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[0-9a-zA-Z.,%\/\s]+\)$/i', $v)) {
            return $v;
        }
        // Palavra-chave (transparent, currentColor, inherit, red, ...).
        if (preg_match('/^[a-zA-Z]+$/', $v)) {
            return $v;
        }

        return $fallback;
    }

    /**
     * Comprimento CSS: número (int/float, com sinal) e unidade opcional
     * (px, mm, cm, in, pt, pc, em, rem, ex, ch, vw, vh, vmin, vmax, %).
     * Aceita string ou número. Qualquer outra coisa → `$fallback`.
     */
    public static function length(string|int|float $value, string $fallback = '0'): string
    {
        $v = trim((string) $value);
        if (preg_match('/^-?[0-9]+(\.[0-9]+)?(px|mm|cm|in|pt|pc|em|rem|ex|ch|vw|vh|vmin|vmax|%)?$/', $v)) {
            return $v;
        }

        return $fallback;
    }

    /**
     * Número puro (int/float, com sinal), sem unidade. Aceita string ou número.
     * Usado onde a unidade é acrescentada pelo template (ex.: `{{ $n }}px`).
     */
    public static function number(string|int|float $value, string $fallback = '0'): string
    {
        $v = trim((string) $value);
        if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $v)) {
            return $v;
        }

        return $fallback;
    }

    /**
     * Valor de `font-family`: letras, números, espaços, vírgulas, hífens, pontos
     * e aspas (simples/duplas). A `StyleHelpers::normalizeFontFamily` já envolve
     * nomes com espaço em aspas; aqui bloqueamos `;{}():<>` que permitiriam sair
     * da declaração. Qualquer valor fora da whitelist → `$fallback`.
     */
    public static function fontFamily(string $value, string $fallback = 'inherit'): string
    {
        $v = trim($value);
        if ($v === '') {
            return $fallback;
        }
        if (preg_match('/^[a-zA-Z0-9 ,\'"\-.]+$/', $v)) {
            return $v;
        }

        return $fallback;
    }
}
