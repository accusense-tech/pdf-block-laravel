<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Data;

/**
 * Variáveis do DOCUMENTO (`document.variables`) — espelho server-side de
 * `packages/react/src/data/variables.ts` + `computed.ts`.
 *
 * O documento carrega as próprias variáveis (defs + valores); o servidor monta
 * o mapa de dados a partir delas, aplica os OVERRIDES de runtime do host (o
 * campo `data` do payload vence o valor salvo) e injeta as AUTOMÁTICAS:
 *  - `sys.hoje`  → `yyyy-MM-dd` (relógio de parede local);
 *  - `sys.agora` → `yyyy-MM-ddTHH:mm:ssZ` (parede local com `Z` literal — o
 *    formatador usa getters UTC nos dois lados, então o texto final é a hora
 *    local de quem rendeu, idêntico ao client).
 * `item.numero`/`item.total` são injetados POR ITEM pelo DataBindingExpander.
 */
class DocumentVariables
{
    /**
     * Mapa global `key → value` das variáveis do documento (sem `undefined`).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function values(array $document): array
    {
        $out = [];
        foreach (self::defs($document) as $def) {
            if (array_key_exists('value', $def)) {
                $out[(string) $def['key']] = $def['value'];
            }
        }
        return $out;
    }

    /**
     * Mapa `path → format`: chave da variável para escalares e chave RELATIVA
     * do campo para itens de lista + defaults das automáticas. Espelha
     * `buildVariablesFormatMap` + `COMPUTED_FORMATS` do client.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, string>
     */
    public static function formatMap(array $document): array
    {
        $out = [
            'sys.hoje'  => 'date:dd/MM/yyyy',
            'sys.agora' => 'datetime:dd/MM/yyyy HH:mm',
        ];
        foreach (self::defs($document) as $def) {
            $key = (string) ($def['key'] ?? '');
            // Parágrafo (longtext) = texto rico por padrão (kind 'rich') — espelha o TS.
            if ($key !== '' && ! empty($def['format'])) {
                $out[$key] = (string) $def['format'];
            } elseif ($key !== '' && ($def['type'] ?? null) === 'longtext') {
                $out[$key] = 'rich';
            }
            foreach (($def['itemFields'] ?? []) as $field) {
                if (! is_array($field) || ! isset($field['key']) || isset($out[(string) $field['key']])) {
                    continue;
                }
                if (! empty($field['format'])) {
                    $out[(string) $field['key']] = (string) $field['format'];
                } elseif (($field['type'] ?? null) === 'longtext') {
                    $out[(string) $field['key']] = 'rich';
                }
            }
        }
        return $out;
    }

    /**
     * Valores das automáticas `sys.*` (espelha `computedValues` do client).
     *
     * @return array<string, mixed>
     */
    public static function computed(?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        return ['sys' => [
            'hoje'  => $now->format('Y-m-d'),
            'agora' => $now->format('Y-m-d\TH:i:s\Z'),
        ]];
    }

    /**
     * Dados COMPLETOS para o render: valores do documento + overrides de
     * runtime do host (vencem) + automáticas `sys.*`.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function buildData(array $document, array $overrides = [], ?\DateTimeInterface $now = null): array
    {
        return array_merge(self::values($document), $overrides, self::computed($now));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private static function defs(array $document): array
    {
        $vars = $document['variables'] ?? null;
        if (! is_array($vars)) {
            return [];
        }
        return array_values(array_filter($vars, fn ($v) => is_array($v) && isset($v['key'])));
    }
}
