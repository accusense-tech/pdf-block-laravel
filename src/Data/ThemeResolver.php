<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Data;

/**
 * Resolução de tokens de tema (design tokens) — espelho exato do resolver client
 * (`packages/react/src/data/theme.ts`).
 *
 * Substitui `{{token:grupo.chave}}` (ex.: `{{token:colors.accent}}`,
 * `{{token:spacing.md}}`) pelos valores definidos em `document['theme']`. Roda
 * ANTES do `DataBindingExpander` (tokens de tema são literais e estáticos).
 *
 * Token PURO num campo (a string é exatamente um token) resolve para o VALOR CRU
 * — preservando o tipo numérico de `spacing`/`radius`, essencial para o CSS de
 * padding/margin/raio sair correto. Token EMBUTIDO resolve por interpolação.
 * Token inexistente → '' (igual a binding inválido); o validador sinaliza órfãos.
 */
class ThemeResolver
{
    /** Regex de um token, idêntico ao lado JS. */
    public const TOKEN_RE = '/\{\{token:([a-zA-Z0-9_.\-]+)\}\}/';

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function resolve(array $document): array
    {
        $theme = $document['theme'] ?? null;
        if (! is_array($theme)) {
            return $document;
        }

        $out = [];
        foreach ($document as $key => $value) {
            // Não resolve a própria definição do tema.
            $out[$key] = $key === 'theme' ? $value : self::walk($value, $theme);
        }
        return $out;
    }

    private static function walk(mixed $node, array $theme): mixed
    {
        if (is_string($node)) {
            return self::resolveString($node, $theme);
        }
        if (is_array($node)) {
            // Gradiente de fundo com REF a um gradiente do tema: expande stops/angle/type
            // da paleta ativa (resolvendo tokens das paradas), mantendo o `ref`. Espelha o
            // resolveThemeDeep do client — reativo/consistente TS↔PHP.
            if (($node['type'] ?? null) === 'gradient' && is_string($node['ref'] ?? null)) {
                $tg = $theme['gradients'][$node['ref']] ?? null;
                if (is_array($tg)) {
                    return array_merge($tg, [
                        'stops' => self::walk($tg['stops'] ?? [], $theme),
                        'ref'   => $node['ref'],
                    ]);
                }
            }
            $out = [];
            foreach ($node as $key => $value) {
                $out[$key] = self::walk($value, $theme);
            }
            return $out;
        }
        return $node;
    }

    private static function resolveString(string $str, array $theme): mixed
    {
        if (! str_contains($str, '{{token:')) {
            return $str;
        }
        // Token puro → valor cru (int/float/string), preservando o tipo numérico.
        $pure = '/^' . substr(self::TOKEN_RE, 1, -1) . '$/';
        if (preg_match($pure, $str, $m)) {
            $v = self::lookup($theme, $m[1]);
            return $v ?? '';
        }
        return preg_replace_callback(
            self::TOKEN_RE,
            function (array $m) use ($theme) {
                $v = self::lookup($theme, $m[1]);
                return $v === null ? '' : (string) $v;
            },
            $str,
        ) ?? $str;
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    private static function lookup(array $theme, string $path): mixed
    {
        $parts = explode('.', $path, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$group, $key] = $parts;
        $bucket = $theme[$group] ?? null;
        if (! is_array($bucket) || ! array_key_exists($key, $bucket)) {
            return null;
        }
        $v = $bucket[$key];
        return (is_string($v) || is_int($v) || is_float($v)) ? $v : null;
    }
}
