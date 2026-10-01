<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Data;

/**
 * Resolução de bindings de dados — espelho exato do resolver client
 * (`packages/react/src/data/bindings.ts`).
 *
 * Substitui tokens `{{bind:path}}` em strings e nós TipTap `variable`
 * (`{ type: 'variable', attrs: { path } }`) pelos valores de um array de dados.
 * Suporta caminhos pontilhados e índices de array (`items[0].title`).
 *
 * Compartilhado entre o modo PDF (`PdfBlockRenderer`) e o modo email
 * (`EmailRenderer`), garantindo paridade de comportamento.
 */
class BindingResolver
{
    /** Regex de um token, idêntico ao lado JS. */
    public const TOKEN_RE = '/\{\{bind:([a-zA-Z0-9_.\[\]]+)\}\}/';

    /**
     * Resolve recursivamente tokens e nós de variável num nó arbitrário da DSL.
     * O `$formatMap` (path → format) aplica a formatação declarada no contrato.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $formatMap
     */
    public static function resolve(mixed $node, array $data, array $formatMap = []): mixed
    {
        if (is_string($node)) {
            return self::resolveString($node, $data, $formatMap);
        }

        if (is_array($node)) {
            // Chip RICO SOZINHO num parágrafo → o parágrafo é SUBSTITUÍDO pelos
            // blocos reais do valor (parágrafos/listas). Espelha o TS
            // (resolveBindingsDeep). No meio de uma frase, cai no fluxo inline.
            if (
                ($node['type'] ?? null) === 'paragraph'
                && is_array($node['content'] ?? null)
                && count($node['content']) === 1
            ) {
                $only = $node['content'][0] ?? null;
                if (
                    is_array($only)
                    && ($only['type'] ?? null) === 'variable'
                    && isset($only['attrs']['path'])
                    && is_string($only['attrs']['path'])
                ) {
                    $path = $only['attrs']['path'];
                    $nodeFormat = $only['attrs']['format'] ?? null;
                    $fmt = (is_string($nodeFormat) && $nodeFormat !== '') ? $nodeFormat : ($formatMap[$path] ?? null);
                    if ($fmt === 'rich') {
                        $blocks = RichText::toBlocks(ValueFormatter::scalarString(self::dotGetRaw($data, $path)));
                        if (RichText::blocksAreBlockLevel($blocks)) {
                            $outer = (isset($only['marks']) && is_array($only['marks'])) ? array_values($only['marks']) : [];
                            if ($outer !== []) {
                                $blocks = array_map(fn (array $b) => self::mergeMarksIntoBlock($b, $outer), $blocks);
                            }
                            // Atributos do parágrafo original (ex.: textAlign)
                            // valem nos parágrafos resultantes.
                            if (isset($node['attrs']) && is_array($node['attrs'])) {
                                $blocks = array_map(function (array $b) use ($node): array {
                                    if (($b['type'] ?? '') === 'paragraph') {
                                        $b['attrs'] = $node['attrs'];
                                    }

                                    return $b;
                                }, $blocks);
                            }

                            return $blocks; // LISTA → o pai emenda no lugar do parágrafo
                        }
                    }
                }
            }
            // Nó TipTap `variable` → nó de texto com o valor resolvido (e formatado).
            if (
                ($node['type'] ?? null) === 'variable'
                && isset($node['attrs']['path'])
                && is_string($node['attrs']['path'])
            ) {
                $path = $node['attrs']['path'];
                // `attrs.format` (formato POR INSERÇÃO) vence o formato da variável — espelha o TS.
                $nodeFormat = $node['attrs']['format'] ?? null;
                $fmt = (is_string($nodeFormat) && $nodeFormat !== '') ? $nodeFormat : ($formatMap[$path] ?? null);
                $raw = self::dotGetRaw($data, $path);
                // MARKS aplicadas ao chip (negrito/cor via toolbar) valem para o
                // valor resolvido — no rico, mesclam com as internas (a interna
                // vence quando o tipo colide). Espelha o TS (resolveBindingsDeep).
                $outer = (isset($node['marks']) && is_array($node['marks'])) ? array_values($node['marks']) : [];
                if ($fmt === 'rich') {
                    // longtext RICO → FRAGMENTO inline (o pai-lista achata; espelha o TS).
                    $frag = RichText::toInlineNodes(ValueFormatter::scalarString($raw));
                    if ($outer !== []) {
                        foreach ($frag as &$part) {
                            if (($part['type'] ?? null) !== 'text') {
                                continue;
                            }
                            $inner = (isset($part['marks']) && is_array($part['marks'])) ? $part['marks'] : [];
                            $innerTypes = array_map(static fn ($m) => $m['type'] ?? '', $inner);
                            $kept = array_values(array_filter($outer, static fn ($m) => ! in_array($m['type'] ?? '', $innerTypes, true)));
                            $merged = array_merge($kept, $inner);
                            if ($merged !== []) {
                                $part['marks'] = $merged;
                            }
                        }
                        unset($part);
                    }
                    return $frag !== [] ? $frag : [['type' => 'text', 'text' => '']];
                }
                $val = ValueFormatter::format($raw, $fmt);
                $out = ['type' => 'text', 'text' => $val];
                if ($outer !== []) {
                    $out['marks'] = $outer;
                }
                return $out;
            }

            // Lista de nós (ex.: `content`): um variable RICO resolve para um
            // FRAGMENTO, EMENDADO no lugar do nó. Só quando o ORIGINAL era um nó
            // variable — achatar qualquer lista-dentro-de-lista corromperia
            // estruturas legítimas (ex.: `rows` de tabela). Espelha o TS.
            if (array_is_list($node)) {
                $out = [];
                foreach ($node as $value) {
                    $resolved = self::resolve($value, $data, $formatMap);
                    // Emenda quando o ORIGINAL era variable OU paragraph (que só
                    // devolve lista no caso chip-sozinho-com-blocos) — espelha o TS.
                    $type = is_array($value) ? ($value['type'] ?? null) : null;
                    if (($type === 'variable' || $type === 'paragraph') && is_array($resolved) && array_is_list($resolved)) {
                        foreach ($resolved as $part) {
                            $out[] = $part;
                        }
                    } else {
                        $out[] = $resolved;
                    }
                }
                return $out;
            }
            $out = [];
            foreach ($node as $key => $value) {
                $out[$key] = self::resolve($value, $data, $formatMap);
            }
            return $out;
        }

        return $node;
    }

    /**
     * Substitui todos os tokens `{{bind:path}}` de uma string pelos valores
     * (formatados conforme o `$formatMap`). Valores não-escalares → ''.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $formatMap
     */
    public static function resolveString(string $str, array $data, array $formatMap = []): string
    {
        if (! str_contains($str, '{{bind:')) {
            return $str;
        }
        return preg_replace_callback(
            self::TOKEN_RE,
            fn (array $m) => ValueFormatter::format(self::dotGetRaw($data, $m[1]), $formatMap[$m[1]] ?? null),
            $str,
        ) ?? $str;
    }

    /**
     * Acesso por caminho pontilhado com índices de array. Retorna SEMPRE escalar
     * como string (compatível com o `EmailRenderer::dotGet` original).
     *
     * @param  array<string, mixed>  $data
     */
    public static function dotGet(array $data, string $path, mixed $default = null): mixed
    {
        $raw = self::dotGetRaw($data, $path);
        if ($raw === null) {
            return $default;
        }
        return is_scalar($raw) ? (string) $raw : $default;
    }

    /**
     * Igual ao `dotGet`, mas preserva o valor cru (arrays/objetos) — usado pelo
     * repetidor para obter a lista de registros de uma fonte.
     *
     * @param  array<string, mixed>  $data
     */
    public static function dotGetRaw(array $data, string $path): mixed
    {
        $normalized = preg_replace('/\[(\d+)\]/', '.$1', $path) ?? $path;
        $cursor = $data;
        foreach (explode('.', $normalized) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
            } else {
                return null;
            }
        }
        return $cursor;
    }

    /**
     * Mescla marks EXTERNAS (do chip) em todo texto de um bloco — a interna
     * vence quando o tipo colide. Espelha `mergeMarksIntoBlock` (bindings.ts).
     *
     * @param  array<string, mixed>  $block
     * @param  list<array<string, mixed>>  $outer
     * @return array<string, mixed>
     */
    private static function mergeMarksIntoBlock(array $block, array $outer): array
    {
        if (($block['type'] ?? '') === 'paragraph') {
            if (is_array($block['content'] ?? null)) {
                $block['content'] = array_map(function ($n) use ($outer) {
                    if (! is_array($n) || ($n['type'] ?? '') !== 'text') {
                        return $n;
                    }
                    $inner = (isset($n['marks']) && is_array($n['marks'])) ? $n['marks'] : [];
                    $innerTypes = array_map(static fn ($m) => $m['type'] ?? '', $inner);
                    $kept = array_values(array_filter($outer, static fn ($m) => ! in_array($m['type'] ?? '', $innerTypes, true)));
                    $merged = array_merge($kept, $inner);
                    if ($merged !== []) {
                        $n['marks'] = $merged;
                    }

                    return $n;
                }, $block['content']);
            }

            return $block;
        }
        if (is_array($block['content'] ?? null)) {
            $block['content'] = array_map(function ($item) use ($outer) {
                if (is_array($item) && is_array($item['content'] ?? null)) {
                    $item['content'] = array_map(fn ($b) => is_array($b) ? self::mergeMarksIntoBlock($b, $outer) : $b, $item['content']);
                }

                return $item;
            }, $block['content']);
        }

        return $block;
    }
}
