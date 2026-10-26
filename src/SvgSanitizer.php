<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

/**
 * Sanitizador de SVG inline — espelho de `sanitizeSvg` em
 * packages/react/src/utils/svg.ts (paridade editor↔PDF).
 *
 * O conteúdo do bloco `svg` é injetado direto no HTML (`{!! … !!}`) e renderizado
 * pelo Chromium headless. Um `<script>` ou handler `on*` dentro do SVG SERIA
 * EXECUTADO durante o render — por isso removemos os vetores de execução antes de
 * inserir. Todo o desenho vetorial (paths, shapes, gradientes, texto, `<use>`,
 * `<image>`) é preservado: nada é rasterizado.
 *
 * Abordagem por blocklist (sem parser DOM completo): cobre os vetores conhecidos
 * de forma pragmática. NÃO é um sandbox — é defesa em profundidade. O Browserless
 * roda em container isolado, limitando o raio de impacto. Para SVG de terceiros
 * não-confiáveis, considere um sanitizador baseado em DOM.
 */
class SvgSanitizer
{
    public static function sanitize(string $svg): string
    {
        $s = trim($svg);
        if ($s === '') {
            return '';
        }
        // Precisa conter um elemento <svg>; caso contrário, descarta (evita HTML cru).
        if (! preg_match('#<svg[\s>]#i', $s)) {
            return '';
        }

        // 1. Remove blocos perigosos com conteúdo: <script>, <foreignObject>
        //    (embute HTML/JS arbitrário) e mídia/iframe/object.
        $s = preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $s) ?? $s;
        $s = preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject\s*>#is', '', $s) ?? $s;
        $s = preg_replace('#<(iframe|embed|object|audio|video)\b[^>]*>.*?</\1\s*>#is', '', $s) ?? $s;
        // Versões self-closing/sem fechamento dos elementos acima.
        $s = preg_replace('#<(script|foreignObject|iframe|embed|object)\b[^>]*/?>#is', '', $s) ?? $s;

        // 2. Remove atributos de evento (onload, onclick, onmouseover, …).
        $s = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $s) ?? $s;
        $s = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $s) ?? $s;
        $s = preg_replace('#\son[a-z]+\s*=\s*[^\s>]+#i', '', $s) ?? $s;

        // 3. Neutraliza esquemas perigosos em href/xlink:href/src.
        $s = preg_replace('#(href|xlink:href|src)\s*=\s*"(\s*(javascript|vbscript|data\s*:\s*text/html))[^"]*"#i', '$1="#"', $s) ?? $s;
        $s = preg_replace("#(href|xlink:href|src)\s*=\s*'(\s*(javascript|vbscript|data\s*:\s*text/html))[^']*'#i", "$1='#'", $s) ?? $s;

        // 4. Remove @import e expression() de blocos <style> internos.
        $s = preg_replace('#@import\b[^;]*;?#i', '', $s) ?? $s;
        $s = preg_replace('#expression\s*\(#i', '(', $s) ?? $s;

        return $s;
    }
}
