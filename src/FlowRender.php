<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

require_once __DIR__ . '/SchemaDefaults.gen.php';

/**
 * Helpers para o render NATIVO do fluxo v3 (Seções/FlowNode) — SEM achatar para
 * v2. Espelha os 3 contextos de coalescência do editor (FlowCanvas.tsx):
 * stripe-flow / structure-flow / column-flow. O grid (ColumnSet) é renderizado
 * nativamente (preserva larguras e, futuramente, spanning); as folhas soltas
 * ganham wrappers de layout sintéticos com os MESMOS defaults do v2 (paridade).
 */
class FlowRender
{
    public static function isColumnSet(array $n): bool
    {
        return ($n['type'] ?? '') === 'columnSet';
    }

    public static function isGroup(array $n): bool
    {
        return ($n['type'] ?? '') === 'group';
    }

    public static function isLeaf(array $n): bool
    {
        $t = $n['type'] ?? '';
        return $t !== 'columnSet' && $t !== 'group';
    }

    /** `meta.hideOnExport` — o bloco não sai no PDF/e-mail (só no editor). */
    public static function isHidden(array $n): bool
    {
        return (bool) ($n['meta']['hideOnExport'] ?? false);
    }

    /**
     * Agrupa um fluxo em "runs" para emissão, coalescendo folhas soltas
     * consecutivas (que viram UM wrapper sintético) e isolando group/columnSet.
     *
     * Nós com `meta.hideOnExport` são DESCARTADOS aqui — é a fonte única dos dois
     * blades de fluxo (stripe-flow/structure-flow). Antes só as FOLHAS eram
     * filtradas (dentro do run), então "Ocultar do PDF" não valia para grupo nem
     * para linha de colunas: o wrapper saía no PDF com todo o conteúdo dentro.
     *
     * @param  list<array<string, mixed>>  $flow
     * @return list<array{kind:string, items?:list<array<string,mixed>>, node?:array<string,mixed>}>
     */
    public static function runs(array $flow): array
    {
        $runs = [];
        $buf = [];
        foreach ($flow as $n) {
            if (! is_array($n) || self::isHidden($n)) {
                continue;
            }
            if (self::isColumnSet($n) || self::isGroup($n)) {
                if ($buf) {
                    $runs[] = ['kind' => 'leaves', 'items' => $buf];
                    $buf = [];
                }
                $runs[] = ['kind' => self::isColumnSet($n) ? 'columnSet' : 'group', 'node' => $n];
            } else {
                $buf[] = $n;
            }
        }
        if ($buf) {
            $runs[] = ['kind' => 'leaves', 'items' => $buf];
        }
        return $runs;
    }

    /**
     * Pré-pass de ESTILOS NOMEADOS (styleRef): percorre as seções resolvendo, em
     * cada nó com `styleRef`, o estilo nomeado correspondente — mescla
     * `named.styles` SOBRE `node.styles` (nome vence) e remove o `styleRef`.
     * Análogo ao `resolveStyles` do TS; deixa as seções "flat" para os blades.
     *
     * @param  list<array<string,mixed>>  $sections
     * @param  list<array<string,mixed>>  $namedStyles
     * @return list<array<string,mixed>>
     */
    public static function resolveStyleRefs(array $sections, array $namedStyles): array
    {
        $map = [];
        foreach ($namedStyles as $ns) {
            if (is_array($ns) && isset($ns['id'])) {
                $map[$ns['id']] = is_array($ns['styles'] ?? null) ? $ns['styles'] : [];
            }
        }
        if (! $map) {
            return $sections;
        }

        return array_map(fn ($s) => is_array($s) ? self::resolveNodeStyleRef($s, $map) : $s, $sections);
    }

    /** Resolve o styleRef de um nó e recursa em `flow`/`columns`. */
    private static function resolveNodeStyleRef(array $node, array $map): array
    {
        $ref = $node['styleRef'] ?? null;
        if (is_string($ref) && isset($map[$ref])) {
            $node['styles'] = StyleHelpers::deepMerge(is_array($node['styles'] ?? null) ? $node['styles'] : [], $map[$ref]);
            unset($node['styleRef']);
        }
        if (isset($node['flow']) && is_array($node['flow'])) {
            $node['flow'] = array_map(fn ($n) => is_array($n) ? self::resolveNodeStyleRef($n, $map) : $n, $node['flow']);
        }
        if (isset($node['columns']) && is_array($node['columns'])) {
            $node['columns'] = array_map(fn ($c) => is_array($c) ? self::resolveNodeStyleRef($c, $map) : $c, $node['columns']);
        }

        return $node;
    }

    /**
     * Headings (TipTap) dos blocos de texto, na ordem do documento (DFS pré-ordem).
     * Espelha core/toc.collectHeadings (TS). Item: ['level','text','blockId','anchorId'].
     *
     * @param  list<array<string,mixed>>  $sections
     * @return list<array{level:int,text:string,blockId:string,anchorId:string}>
     */
    public static function collectHeadings(array $sections, int $maxLevel = 6): array
    {
        $out = [];
        $walk = function (array $flow) use (&$walk, &$out, $maxLevel) {
            foreach ($flow as $n) {
                if (! is_array($n)) {
                    continue;
                }
                $type = $n['type'] ?? '';
                if ($type === 'columnSet') {
                    foreach ($n['columns'] ?? [] as $col) {
                        if (is_array($col)) {
                            $walk($col['flow'] ?? []);
                        }
                    }
                } elseif ($type === 'group') {
                    $walk($n['flow'] ?? []);
                } elseif ($type === 'text' && is_array($n['content']['content'] ?? null)) {
                    $h = 0;
                    foreach ($n['content']['content'] as $child) {
                        if (is_array($child) && ($child['type'] ?? '') === 'heading') {
                            $level = (int) ($child['attrs']['level'] ?? 1);
                            if ($level <= $maxLevel) {
                                $out[] = [
                                    'level' => $level,
                                    'text' => self::tipText($child),
                                    'blockId' => (string) ($n['id'] ?? ''),
                                    'anchorId' => 'pdfb-h-' . ($n['id'] ?? '') . '-' . $h,
                                ];
                            }
                            $h++;
                        }
                    }
                }
            }
        };
        foreach ($sections as $sec) {
            if (is_array($sec)) {
                $walk($sec['flow'] ?? []);
            }
        }

        return $out;
    }

    /**
     * IDs de blocos que são ALVO de uma âncora de clique — referenciados por um
     * href `#<blockId>` (mark `link` do texto ou `url` de um botão). Espelha
     * core/anchor (TS): ignora âncoras de heading (`#pdfb-h-...`) e só retorna os
     * ids que EXISTEM como bloco. Usado para emitir `id` só nos blocos-alvo.
     *
     * @param  list<array<string,mixed>>  $sections
     * @return list<string>
     */
    public static function collectAnchorTargets(array $sections): array
    {
        $allIds = []; // todos os ids de bloco do documento (set)
        $refs = [];   // ids referenciados por hrefs de âncora (set)

        $addHref = function ($href) use (&$refs): void {
            if (is_string($href) && strlen($href) > 1 && $href[0] === '#' && ! str_starts_with($href, '#pdfb-h-')) {
                $refs[substr($href, 1)] = true;
            }
        };

        // Marks `link` dentro do conteúdo TipTap de um bloco de texto.
        $walkInline = function ($node) use (&$walkInline, $addHref): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node['marks'] ?? [] as $mark) {
                if (is_array($mark) && ($mark['type'] ?? '') === 'link') {
                    $addHref($mark['attrs']['href'] ?? null);
                }
            }
            foreach ($node['content'] ?? [] as $child) {
                $walkInline($child);
            }
        };

        $walk = function (array $flow) use (&$walk, &$allIds, $addHref, $walkInline): void {
            foreach ($flow as $n) {
                if (! is_array($n)) {
                    continue;
                }
                if (isset($n['id']) && is_string($n['id'])) {
                    $allIds[$n['id']] = true;
                }
                $type = $n['type'] ?? '';
                if ($type === 'columnSet') {
                    foreach ($n['columns'] ?? [] as $col) {
                        if (is_array($col)) {
                            if (isset($col['id']) && is_string($col['id'])) {
                                $allIds[$col['id']] = true;
                            }
                            $walk($col['flow'] ?? []);
                        }
                    }
                } elseif ($type === 'group') {
                    $walk($n['flow'] ?? []);
                } elseif ($type === 'text' && is_array($n['content']['content'] ?? null)) {
                    foreach ($n['content']['content'] as $child) {
                        $walkInline($child);
                    }
                } elseif ($type === 'button') {
                    $addHref($n['url'] ?? null);
                }
            }
        };

        foreach ($sections as $sec) {
            if (is_array($sec)) {
                if (isset($sec['id']) && is_string($sec['id'])) {
                    $allIds[$sec['id']] = true;
                }
                $walk($sec['flow'] ?? []);
            }
        }

        // Só alvos que existem como bloco (evita id órfão p/ referência quebrada).
        return array_values(array_keys(array_intersect_key($refs, $allIds)));
    }

    /** Texto plano de um nó TipTap (recursivo); `variable` usa o label. */
    private static function tipText(array $node): string
    {
        $t = $node['type'] ?? '';
        if ($t === 'text') {
            return $node['text'] ?? '';
        }
        if ($t === 'variable') {
            return $node['attrs']['label'] ?? '';
        }
        $s = '';
        foreach ($node['content'] ?? [] as $c) {
            if (is_array($c)) {
                $s .= self::tipText($c);
            }
        }

        return $s;
    }

    /**
     * Defaults de estilo do wrapper sintético — da FONTE ÚNICA
     * (`packages/schema/defaults.json`), como todo o resto.
     */
    public static function defaultStyles(): array
    {
        return SchemaDefaults::blockStyles();
    }

    /**
     * grid-template-columns das colunas de um ColumnSet — espelha o FlowCanvas
     * (minmax(0,${w}fr), idêntico ao structure.blade do v2).
     *
     * @param  list<array<string, mixed>>  $columns
     */
    public static function gridTracks(array $columns): string
    {
        $count = max(count($columns), 1);
        $tracks = implode(' ', array_map(
            fn ($c) => 'minmax(0, ' . ($c['width'] ?? (100 / $count)) . 'fr)',
            $columns,
        ));
        return $tracks !== '' ? $tracks : 'minmax(0, 1fr)';
    }

    /**
     * CSS da DISPOSIÇÃO explícita de um container (`row`) — espelho de
     * `rowContainerCss` em core/rowLayout.ts. `direction:'column'` mantém o
     * empilhamento, mas com distribuição vertical/alinhamento horizontal.
     * Devolve '' quando não há `row` (fluxo normal).
     *
     * @param  array<string, mixed>|null  $row
     */
    public static function rowCss(?array $row): string
    {
        if (! $row) {
            return '';
        }
        $column = ($row['direction'] ?? 'row') === 'column';

        return implode('', [
            'display:flex;flex-direction:' . ($column ? 'column' : 'row') . ';',
            'flex-wrap:' . ($column || ($row['wrap'] ?? true) === false ? 'nowrap' : 'wrap') . ';',
            'gap:' . (int) ($row['gap'] ?? 12) . 'px;',
            'justify-content:' . self::rowJustify($row['justify'] ?? null) . ';',
            'align-items:' . self::rowAlign($row['align'] ?? null) . ';',
        ]);
    }

    /**
     * `justify-content` do container com `row` (o eixo do arranjo) — espelho de
     * core/rowLayout.ts (client).
     */
    public static function rowJustify(?string $v): string
    {
        return match ($v) {
            'center' => 'center',
            'end' => 'flex-end',
            'between' => 'space-between',
            default => 'flex-start',
        };
    }

    /** `align-items` do grupo LADO A LADO (`row.align`). */
    public static function rowAlign(?string $v): string
    {
        return match ($v) {
            'center' => 'center',
            'bottom' => 'flex-end',
            'top' => 'flex-start',
            default => 'stretch',
        };
    }

    /**
     * Tipografia HERDADA de um container (grupo/linha/coluna) como CSS inline —
     * espelho de `core/typography.ts` (client). Emite SÓ o que está definido: o
     * resto herda do documento, e um bloco de dentro que define o seu vence.
     *
     * @param  array<string, mixed>|null  $typography
     */
    public static function typography(?array $typography): string
    {
        if (! $typography) {
            return '';
        }
        $css = '';
        if (! empty($typography['fontFamily'])) {
            $css .= 'font-family:' . StyleHelpers::normalizeFontFamily((string) $typography['fontFamily']) . ';';
        }
        if (! empty($typography['fontSize'])) {
            $css .= 'font-size:' . (int) $typography['fontSize'] . 'px;';
        }
        if (! empty($typography['fontWeight'])) {
            $css .= 'font-weight:' . (int) $typography['fontWeight'] . ';';
        }
        if (! empty($typography['fontColor'])) {
            $css .= 'color:' . $typography['fontColor'] . ';';
        }
        return $css;
    }

    /**
     * ESTICADO NA ALTURA (`fillHeight`): o nó pede a altura livre do container.
     * Espelho de `core/fillHeight.ts` — quem hospeda vira coluna flex e o grupo
     * (ou banner) cresce nela. `banner.fillHeight` continua valendo: é onde o
     * campo nasceu e documentos antigos ainda o trazem lá.
     *
     * @param  array<string, mixed>  $node
     */
    public static function wantsFillHeight(array $node): bool
    {
        return ($node['fillHeight'] ?? false) === true
            || (($node['variant'] ?? null) === 'banner' && ($node['banner']['fillHeight'] ?? false) === true);
    }

    /**
     * ESTICADO NA LARGURA (`fillWidth`): o grupo ocupa o espaço livre do
     * container lado a lado. Espelho de `wantsFillWidth` em core/fillHeight.ts.
     *
     * @param  array<string, mixed>  $node
     */
    public static function wantsFillWidth(array $node): bool
    {
        return ($node['fillWidth'] ?? false) === true;
    }

    /**
     * Algum filho DIRETO do fluxo pede altura cheia?
     *
     * @param  array<int, mixed>|null  $flow
     */
    public static function hasFillHeightChild(?array $flow): bool
    {
        foreach ($flow ?? [] as $n) {
            if (is_array($n) && self::wantsFillHeight($n)) {
                return true;
            }
        }

        return false;
    }

    /** CSS do CONTAINER que hospeda um filho esticado (coluna/grupo). */
    public const FILL_PARENT_CSS = 'display:flex;flex-direction:column;';

    /** CSS do próprio banner esticado (a `minHeight` segue como piso). */
    public const FILL_CHILD_CSS = 'flex:1 1 auto;display:flex;flex-direction:column;';

    /** CSS do grupo esticado na LARGURA (espelho de FILL_WIDTH_CSS_TEXT). */
    public const FILL_WIDTH_CSS = 'flex:1 1 0%;min-width:0;align-self:stretch;';

    /** align-items (grid) a partir do verticalAlignment. */
    public static function vAlign(string $v): string
    {
        return match ($v) {
            'center' => 'center',
            'bottom' => 'end',
            'stretch' => 'stretch',
            default => 'start',
        };
    }

    /** MODO GRADE (spanning) ativo se há gridColumns ou alguma coluna tem colSpan. */
    public static function useGrid(array $columns, ?int $gridColumns): bool
    {
        if ($gridColumns !== null) {
            return true;
        }
        foreach ($columns as $c) {
            if (is_array($c) && isset($c['colSpan'])) {
                return true;
            }
        }
        return false;
    }

    /** Faixas (tracks) que a coluna ocupa no modo grade — colSpan ou derivado da %. */
    public static function span(array $col, int $gridCols): int
    {
        $s = $col['colSpan'] ?? (($col['width'] ?? (100 / max($gridCols, 1))) / 100 * $gridCols);
        return max(1, min($gridCols, (int) round($s)));
    }
}
