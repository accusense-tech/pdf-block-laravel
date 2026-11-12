<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Data;

/**
 * Resolve os DADOS do documento — o espelho server-side da camada de variáveis
 * do editor.
 *
 * Modelo (LOOP MATERIALIZADO): não existe mais "bloco-modelo repetido em tempo
 * de render". Cada item de uma lista tem o SEU bloco no documento, marcado com
 * `itemScope { list, item, index }`. Aqui:
 *
 *  - as séries de blocos de lista são RECONCILIADAS com os dados de runtime
 *    (ver {@see ItemScopeExpander}): registro novo clona o bloco base, bloco de
 *    registro inexistente sai;
 *  - cada bloco resolve seus `{{bind:...}}` (e nós `variable`) contra o ESCOPO
 *    dele: globais + campos do SEU item + `item.numero`/`item.total`;
 *  - blocos sem `itemScope` resolvem contra o escopo corrente (no topo, o `data`
 *    global = valores das variáveis + overrides do host + `sys.*`).
 *
 * Convenção: cada item de lista carrega `__id` (id estável) para o casamento.
 */
class DataBindingExpander
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $formatMap  path → format (ver ValueFormatter)
     * @return array<string, mixed>
     */
    public static function expand(array $document, array $data, array $formatMap = []): array
    {
        // v3 (Seções/fluxo): resolve o `flow` de cada seção. v2: `blocks`.
        // O modelo é o MESMO (blocos com `itemScope` entre irmãos); só muda o nome
        // dos containers (flow/columns[].flow no v3 vs children/columns[].children).
        if (isset($document['sections']) && is_array($document['sections'])) {
            $document['sections'] = array_map(function ($sec) use ($data, $formatMap) {
                if (is_array($sec) && isset($sec['flow']) && is_array($sec['flow'])) {
                    $sec['flow'] = self::expandList($sec['flow'], $data, $formatMap);
                }
                return $sec;
            }, $document['sections']);
            // Cabeçalho/rodapé document-level (v3): mesmo modelo das seções — um `flow`
            // de FlowNodes. Sem resolvê-los, os `{{bind:...}}`/nós `variable` da mobília
            // NÃO resolvem e somem no PDF. Escopo = data global.
            foreach (['header', 'footer'] as $slot) {
                if (isset($document[$slot]['flow']) && is_array($document[$slot]['flow'])) {
                    $document[$slot]['flow'] = self::expandList($document[$slot]['flow'], $data, $formatMap);
                }
            }
            return $document;
        }
        $document['blocks'] = self::expandList($document['blocks'] ?? [], $data, $formatMap);
        return $document;
    }

    /**
     * Resolve uma lista de blocos IRMÃOS: reconcilia as séries de lista com os
     * dados e resolve cada bloco no escopo dele. `$scope` é o mapa de dados
     * corrente (data global no topo; escopo do item dentro de um bloco de lista).
     *
     * @param  list<array<string, mixed>>  $list
     * @param  array<string, mixed>  $scope
     * @param  array<string, string>  $formatMap
     * @return list<array<string, mixed>>
     */
    private static function expandList(array $list, array $scope, array $formatMap): array
    {
        $out = [];
        foreach (ItemScopeExpander::reconcile($list, $scope) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $itemScope = ItemScopeExpander::scopeOf($block);
            if ($itemScope === null) {
                $out[] = self::expandBlock($block, $scope, $formatMap);
                continue;
            }

            $records = BindingResolver::dotGetRaw($scope, (string) $itemScope['list']);
            $records = is_array($records) ? array_values($records) : [];
            $hit = ItemScopeExpander::recordForScope($records, $itemScope);
            if ($hit === null) {
                continue; // item inexistente (lista vazia) → o bloco não sai no PDF
            }
            // Escopo do item: globais + campos do item (sombreiam) + item.*
            // (espelha buildItemRecord do client).
            $itemData = array_merge($scope, $hit['record'], [
                'item' => ['numero' => $hit['index'] + 1, 'total' => count($records)],
            ]);
            unset($block['itemScope']); // consumido — não vai para a saída
            $out[] = self::expandBlock($block, $itemData, $formatMap);
        }

        return $out;
    }

    /**
     * Resolve um único bloco no escopo dado: seus escalares/nós de variável e a
     * descida em `children`/`flow`/`columns` (onde podem viver outras séries).
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $scope
     * @param  array<string, string>  $formatMap
     * @return array<string, mixed>
     */
    private static function expandBlock(array $block, array $scope, array $formatMap): array
    {
        $out = [];
        foreach ($block as $key => $value) {
            if (($key === 'children' || $key === 'flow') && is_array($value)) {
                // v2: `children` (filhos de stripe/coluna). v3: `flow` (FlowNodes de
                // Section/Group/coluna). Ambos são listas de nós irmãos.
                $out[$key] = self::expandList($value, $scope, $formatMap);
            } elseif ($key === 'columns' && is_array($value)) {
                $out[$key] = array_map(function ($col) use ($scope, $formatMap) {
                    if (! is_array($col)) {
                        return $col;
                    }
                    // v3: a coluna tem `flow`; v2: tem `children`.
                    $childKey = (isset($col['flow']) && is_array($col['flow'])) ? 'flow' : 'children';
                    $children = $col[$childKey] ?? [];
                    $rest = $col;
                    unset($rest[$childKey]);
                    $rest = BindingResolver::resolve($rest, $scope, $formatMap);
                    $rest[$childKey] = is_array($children) ? self::expandList($children, $scope, $formatMap) : $children;
                    return $rest;
                }, $value);
            } else {
                // content (TipTap), props de plugin, url, src, etc. → resolve no escopo.
                $out[$key] = BindingResolver::resolve($value, $scope, $formatMap);
            }
        }

        return $out;
    }
}
