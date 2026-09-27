<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Data;

/**
 * LOOP MATERIALIZADO — reconciliação das SÉRIES de blocos de lista.
 * Espelho de `packages/react/src/core/itemScope.ts` (client).
 *
 * No editor, ligar uma lista a um bloco cria N blocos REAIS (um por item), cada
 * um com `itemScope { list, item, index }`. No render o documento chega com
 * esses N blocos — mas os DADOS podem ser outros (o host manda os registros de
 * runtime). Esta classe alinha as duas coisas, entre IRMÃOS de um mesmo fluxo:
 *
 *  - casa cada registro com o bloco dele por `item` (o `__id` do registro);
 *  - se NENHUM id casar (dados totalmente novos), casa por POSIÇÃO;
 *  - registro sem bloco → clona o bloco BASE da série (o primeiro), preservando
 *    a edição feita nele; bloco sem registro → sai do documento;
 *  - sem registro nenhum, a série inteira some (lista vazia não rende nada).
 *
 * O `DataBindingExpander` chama isto antes de resolver os `{{bind:...}}` e usa
 * `itemScope` para escolher o escopo de cada bloco.
 */
class ItemScopeExpander
{
    /** Chave do id estável de um item de lista (espelha ITEM_ID_KEY no TS). */
    public const ITEM_ID_KEY = '__id';

    /** `itemScope` do nó, quando presente e válido. @return array<string,mixed>|null */
    public static function scopeOf(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }
        $sc = $node['itemScope'] ?? null;

        return (is_array($sc) && isset($sc['list']) && is_string($sc['list'])) ? $sc : null;
    }

    /** `__id` de um registro, ou `null`. */
    public static function itemId(mixed $record): ?string
    {
        if (! is_array($record)) {
            return null;
        }
        $id = $record[self::ITEM_ID_KEY] ?? null;

        return (is_string($id) && $id !== '') ? $id : null;
    }

    /**
     * Registro que um `itemScope` resolve: por `item` (id) e, se não existir,
     * pela posição. `null` quando nem um nem outro casam.
     *
     * @param  list<mixed>  $records
     * @param  array<string, mixed>  $scope
     * @return array{record: array<string,mixed>, index: int}|null
     */
    public static function recordForScope(array $records, array $scope): ?array
    {
        $item = isset($scope['item']) && is_string($scope['item']) ? $scope['item'] : null;
        if ($item !== null) {
            foreach ($records as $i => $rec) {
                if (self::itemId($rec) === $item) {
                    return ['record' => is_array($rec) ? $rec : [], 'index' => $i];
                }
            }
        }
        $index = isset($scope['index']) && is_int($scope['index']) ? $scope['index'] : 0;
        if ($index >= 0 && $index < count($records)) {
            $rec = $records[$index];

            return ['record' => is_array($rec) ? $rec : [], 'index' => $index];
        }

        return null;
    }

    /**
     * Reconcilia TODAS as séries de uma lista de irmãos com os dados do escopo.
     * Devolve a nova lista de nós (na ordem final). Não muta a entrada.
     *
     * @param  list<mixed>  $flow
     * @param  array<string, mixed>  $scope  dados correntes (globais ou do item pai)
     * @return list<mixed>
     */
    public static function reconcile(array $flow, array $scope): array
    {
        $lists = [];
        foreach ($flow as $node) {
            $sc = self::scopeOf($node);
            if ($sc !== null && ! in_array($sc['list'], $lists, true)) {
                $lists[] = $sc['list'];
            }
        }
        if (! $lists) {
            return array_values($flow);
        }

        $out = array_values($flow);
        foreach ($lists as $list) {
            $records = BindingResolver::dotGetRaw($scope, (string) $list);
            $records = is_array($records) ? array_values($records) : [];
            $out = self::reconcileList($out, (string) $list, $records);
        }

        return $out;
    }

    /**
     * Reconcilia UMA série (blocos de `$list`) com `$records`.
     *
     * @param  list<mixed>  $flow
     * @param  list<mixed>  $records
     * @return list<mixed>
     */
    private static function reconcileList(array $flow, string $list, array $records): array
    {
        // Posições (índices em $flow) dos blocos da série, na ordem.
        $series = [];
        foreach ($flow as $i => $node) {
            $sc = self::scopeOf($node);
            if ($sc !== null && $sc['list'] === $list) {
                $series[] = $i;
            }
        }
        if (! $series) {
            return $flow;
        }

        // Sem registros: a série some do render (lista vazia não rende nada — no
        // editor sobra a semente, que aqui simplesmente não sai).
        if (! $records) {
            $drop = array_flip($series);

            return array_values(array_filter(
                $flow,
                static fn ($i) => ! isset($drop[$i]),
                ARRAY_FILTER_USE_KEY,
            ));
        }

        $base = $flow[$series[0]];

        // ── Casamento: por id; se nenhum casar, por posição ──
        $usedSlot = [];   // posição na série → true
        $perRecord = [];  // índice do registro → posição na série (ou null)
        foreach ($records as $r => $rec) {
            $perRecord[$r] = null;
            $id = self::itemId($rec);
            if ($id === null) {
                continue;
            }
            foreach ($series as $s => $flowIdx) {
                if (isset($usedSlot[$s])) {
                    continue;
                }
                $sc = self::scopeOf($flow[$flowIdx]);
                if ($sc !== null && ($sc['item'] ?? null) === $id) {
                    $usedSlot[$s] = true;
                    $perRecord[$r] = $s;
                    break;
                }
            }
        }
        if (! $usedSlot) {
            foreach ($records as $r => $_rec) {
                if (isset($series[$r])) {
                    $usedSlot[$r] = true;
                    $perRecord[$r] = $r;
                }
            }
        }

        // ── Monta a saída: os não-série ficam onde estão; a série é reescrita
        //    na posição do PRIMEIRO bloco dela, na ordem dos registros. ──
        $inSeries = array_flip($series);
        $rebuilt = [];
        foreach ($records as $r => $rec) {
            $slot = $perRecord[$r];
            $node = $slot !== null ? $flow[$series[$slot]] : self::cloneForItem($base);
            $node['itemScope'] = array_filter([
                'list' => $list,
                'item' => self::itemId($rec),
                'index' => $r,
            ], static fn ($v) => $v !== null);
            $node['itemScope']['index'] = $r; // index 0 sobrevive ao array_filter
            $rebuilt[] = $node;
        }

        $out = [];
        $placed = false;
        foreach ($flow as $i => $node) {
            if (isset($inSeries[$i])) {
                if (! $placed) {
                    foreach ($rebuilt as $n) {
                        $out[] = $n;
                    }
                    $placed = true;
                }
                continue; // os demais blocos da série já foram reescritos
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * Clone de um bloco da série para um item novo: ids REGENERADOS (o documento
     * não pode ter id duplicado) e sem o NOME do bloco (nome é chave única de
     * troca de template — espelha `cloneForItem` no TS).
     *
     * @param  mixed  $node
     * @return array<string, mixed>
     */
    private static function cloneForItem(mixed $node): array
    {
        $clone = is_array($node) ? $node : [];
        $clone = self::renewIds($clone);
        if (isset($clone['meta']['name'])) {
            unset($clone['meta']['name']);
        }

        return $clone;
    }

    /**
     * Ids novos em toda a subárvore. Determinístico dentro de um render (contador
     * de processo) — só precisa ser ÚNICO no documento.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function renewIds(array $node): array
    {
        static $seq = 0;
        if (isset($node['id']) && is_string($node['id'])) {
            $node['id'] = $node['id'].'::item'.(++$seq);
        }
        foreach (['flow', 'children', 'columns'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                $node[$key] = array_map(
                    static fn ($child) => is_array($child) ? self::renewIds($child) : $child,
                    $node[$key],
                );
            }
        }

        return $node;
    }
}
