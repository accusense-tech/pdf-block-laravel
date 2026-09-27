<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

require_once __DIR__ . '/SchemaDefaults.gen.php';

/**
 * Migração entre os modelos v2 (stripe/structure/column) e v3 (Section/fluxo).
 *
 * Espelho 1:1 de `core/migrate.ts` (React). O PDF é renderizado NATIVAMENTE do v3
 * (PdfBlockRenderer + blades `v3/*`), usando `v3Envelope` só para o "chrome" da
 * página. `v3ToV2` permanece como PONTE para o e-mail (congelado v2, D3) e para
 * testes/compat; NÃO é mais o caminho de render do PDF. PURO e determinístico.
 */
class DocumentMigrator
{
    private const WIDTH_EPS = 0.01;

    /** @return array<string, mixed> */
    private static function defaultMeta(): array
    {
        return ['hideOnExport' => false, 'locked' => false, 'breakBefore' => false, 'breakAfter' => false, 'keepTogether' => false];
    }

    /**
     * Defaults de estilo — vêm da FONTE ÚNICA (`packages/schema/defaults.json`),
     * a mesma que alimenta `DEFAULT_BLOCK_STYLES` no TS. `pnpm parity` reprova
     * qualquer drift entre os dois lados.
     *
     * @return array<string, mixed>
     */
    private static function defaultStyles(): array
    {
        return SchemaDefaults::blockStyles();
    }

    private static function isColumnSet(array $n): bool { return ($n['type'] ?? '') === 'columnSet'; }
    private static function isGroup(array $n): bool { return ($n['type'] ?? '') === 'group'; }
    private static function isStructure(array $n): bool { return ($n['type'] ?? '') === 'structure'; }

    /**
     * COMPAT de leitura: documentos antigos traziam `repeat: { list }` (um modelo
     * repetido em tempo de render). O modelo atual são N blocos com `itemScope`
     * (ver Data/ItemScopeExpander) — a leitura converte para o `itemScope` do 1º
     * item e a reconciliação cria os blocos dos demais registros.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>  chave `itemScope` (ou vazio)
     */
    private static function itemScopeOf(array $node): array
    {
        if (isset($node['itemScope']) && is_array($node['itemScope'])) {
            return ['itemScope' => $node['itemScope']];
        }
        $list = $node['repeat']['list'] ?? null;

        return is_string($list) && $list !== '' ? ['itemScope' => ['list' => $list, 'index' => 0]] : [];
    }

    /** O nó está ligado a um item de lista (modelo atual ou `repeat` legado)? */
    private static function hasItemBinding(array $node): bool
    {
        return isset($node['itemScope']) || isset($node['repeat']['list']);
    }

    /**
     * Campos do CONTAINER que não têm equivalente estrutural no v2 e por isso
     * precisam viajar em CIMA da projeção, nas duas direções: `row` (lado a
     * lado) e `typography` (fonte herdada). O host grava a projeção v2 — sem
     * este carry, o documento voltava do banco sem eles. Espelha `rowOf`/`typoOf`
     * em core/migrate.ts.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function carryOf(array $node): array
    {
        $out = [];
        foreach (['row', 'typography'] as $k) {
            if (isset($node[$k]) && is_array($node[$k]) && $node[$k] !== []) {
                $out[$k] = $node[$k];
            }
        }

        return $out;
    }

    /** Tem algum campo de container que impeça dissolver o wrapper na migração? */
    private static function hasCarry(array $node): bool
    {
        return self::carryOf($node) !== [];
    }

    // ─── v2 → v3 ─────────────────────────────────────────────────

    private static function edgeEq(array $a, array $b): bool
    {
        return ($a['top'] ?? 0) === ($b['top'] ?? 0) && ($a['right'] ?? 0) === ($b['right'] ?? 0)
            && ($a['bottom'] ?? 0) === ($b['bottom'] ?? 0) && ($a['left'] ?? 0) === ($b['left'] ?? 0);
    }

    private static function isDefaultStyles(?array $s): bool
    {
        if ($s === null) return true;
        $d = self::defaultStyles();
        $side = fn(array $a, array $b) => ($a['width'] ?? 0) === ($b['width'] ?? 0) && ($a['style'] ?? 'none') === ($b['style'] ?? 'none');
        $b = $s['border'] ?? [];
        $bg = $s['background'] ?? [];
        return self::edgeEq($s['padding'] ?? [], $d['padding']) && self::edgeEq($s['margin'] ?? [], $d['margin'])
            && $side($b['top'] ?? [], $d['border']['top']) && $side($b['right'] ?? [], $d['border']['right'])
            && $side($b['bottom'] ?? [], $d['border']['bottom']) && $side($b['left'] ?? [], $d['border']['left'])
            && ($s['borderRadius']['topLeft'] ?? 0) === 0 && ($s['borderRadius']['topRight'] ?? 0) === 0
            && ($s['borderRadius']['bottomRight'] ?? 0) === 0 && ($s['borderRadius']['bottomLeft'] ?? 0) === 0
            && !($s['shadow']['enabled'] ?? false) && ($s['opacity'] ?? 1) === 1
            && ($bg['type'] ?? 'solid') === 'solid' && ($bg['color'] ?? '') === 'transparent';
    }

    private static function isTrivialMeta(?array $m): bool
    {
        if ($m === null) return true;
        return empty($m['hideOnExport']) && empty($m['locked']) && empty($m['breakBefore'])
            && empty($m['breakAfter']) && empty($m['keepTogether']) && empty($m['hideOnMobile']);
    }

    /** @return list<array<string, mixed>> */
    private static function migrateColumnFlow(array $children): array
    {
        $out = [];
        foreach ($children ?? [] as $child) {
            if (self::isStructure($child)) {
                foreach (self::migrateStructure($child) as $n) $out[] = $n;
            } else {
                $out[] = $child; // folha/custom reusado
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function migrateStructure(array $st): array
    {
        if (($st['variant'] ?? null) === 'banner') {
            $flow = [];
            foreach ($st['columns'] ?? [] as $c) {
                foreach (self::migrateColumnFlow($c['children'] ?? []) as $n) $flow[] = $n;
            }
            $group = [
                'id' => $st['id'], 'type' => 'group', 'meta' => $st['meta'], 'styles' => $st['styles'], 'flow' => $flow,
                'variant' => 'banner',
                ...(($st['verticalAlignment'] ?? 'center') !== 'center' ? ['verticalAlignment' => $st['verticalAlignment']] : []),
                'banner' => array_filter([
                    'backgroundImage' => $st['backgroundImage'] ?? null, 'backgroundSize' => $st['backgroundSize'] ?? null,
                    'backgroundPosition' => $st['backgroundPosition'] ?? null, 'minHeight' => $st['minHeight'] ?? null,
                    'fillHeight' => $st['fillHeight'] ?? null,
                    'adjust' => $st['adjust'] ?? null,
                    'overlayColor' => $st['overlayColor'] ?? null, 'overlayOpacity' => $st['overlayOpacity'] ?? null,
                ], fn($v) => $v !== null),
            ];
            $group = array_merge($group, self::itemScopeOf($st), self::carryOf($st));
            return [$group];
        }

        $cols = $st['columns'] ?? [];
        // Uma coluna 100% sem nada próprio: a structure é só a caixa em volta do
        // fluxo — a projeção de um GRUPO (espelha migrate.ts::migrateStructure).
        $singleBox = count($cols) === 1 && abs(($cols[0]['width'] ?? 100) - 100) < self::WIDTH_EPS
            && self::isDefaultStyles($cols[0]['styles'] ?? null) && ! self::hasCarry($cols[0]);

        if (
            $singleBox && self::isDefaultStyles($st['styles'] ?? null)
            && self::isTrivialMeta($st['meta'] ?? null) && ! self::hasItemBinding($st)
            && ! self::hasCarry($st)
            && ($st['fillWidth'] ?? false) !== true && ($st['fillHeight'] ?? false) !== true
        ) {
            return self::migrateColumnFlow($cols[0]['children'] ?? []);
        }

        // Caixa COM algo próprio (estilo, meta, disposição, esticar) → GRUPO.
        if ($singleBox) {
            $group = [
                'id' => $st['id'], 'type' => 'group', 'meta' => $st['meta'], 'styles' => $st['styles'],
                'flow' => self::migrateColumnFlow($cols[0]['children'] ?? []),
            ];
            if (($st['fillWidth'] ?? false) === true) $group['fillWidth'] = true;
            if (($st['fillHeight'] ?? false) === true) $group['fillHeight'] = true;
            $group = array_merge($group, self::itemScopeOf($st), self::carryOf($st));
            return [$group];
        }

        $columns = [];
        foreach ($cols as $c) {
            $col = ['id' => $c['id'], 'width' => $c['width'], 'styles' => $c['styles'], 'flow' => self::migrateColumnFlow($c['children'] ?? [])];
            if (isset($c['responsive'])) $col['responsive'] = $c['responsive'];
            $col = array_merge($col, self::carryOf($c));
            $columns[] = $col;
        }
        $columnSet = [
            'id' => $st['id'], 'type' => 'columnSet', 'meta' => $st['meta'], 'styles' => $st['styles'],
            'columns' => $columns, 'columnGap' => $st['columnGap'] ?? 0, 'verticalAlignment' => $st['verticalAlignment'] ?? 'top',
        ];
        $columnSet = array_merge($columnSet, self::itemScopeOf($st), self::carryOf($st));
        return [$columnSet];
    }

    /** @return list<array<string, mixed>> */
    private static function migrateStripe(array $stripe): array
    {
        $inner = [];
        foreach ($stripe['children'] ?? [] as $st) {
            foreach (self::migrateStructure($st) as $n) $inner[] = $n;
        }
        $trivial = self::isDefaultStyles($stripe['styles'] ?? null) && self::isTrivialMeta($stripe['meta'] ?? null)
            && (($stripe['contentMaxWidth'] ?? 0) === 0) && (($stripe['contentAlignment'] ?? 'center') === 'center')
            && ! self::hasItemBinding($stripe) && ! self::hasCarry($stripe);
        if ($trivial) return $inner;

        $group = [
            'id' => $stripe['id'], 'type' => 'group', 'meta' => $stripe['meta'], 'styles' => $stripe['styles'], 'flow' => $inner,
            'contentMaxWidth' => $stripe['contentMaxWidth'] ?? 0, 'contentAlignment' => $stripe['contentAlignment'] ?? 'center',
        ];
        $group = array_merge($group, self::itemScopeOf($stripe), self::carryOf($stripe));
        return [$group];
    }

    /**
     * Migra um documento v2 para v3 (1 seção). PURA.
     *
     * @param  array<string, mixed>  $v2
     * @return array<string, mixed>
     */
    public static function v2ToV3(array $v2): array
    {
        $ps = $v2['pageSettings'] ?? [];
        $gs = $v2['globalStyles'] ?? [];
        $flow = [];
        foreach ($v2['blocks'] ?? [] as $stripe) {
            foreach (self::migrateStripe($stripe) as $n) $flow[] = $n;
        }
        $pageSetup = ['paperSize' => $ps['paperSize'] ?? null, 'orientation' => $ps['orientation'] ?? null, 'margins' => $ps['margins'] ?? null, 'defaultFontFamily' => $ps['defaultFontFamily'] ?? null];
        foreach (['pageMode', 'header', 'footer'] as $k) if (isset($ps[$k])) $pageSetup[$k] = $ps[$k];
        foreach (['pageBackground', 'contentBackground', 'contentWidth'] as $k) if (isset($gs[$k])) $pageSetup[$k] = $gs[$k];
        $sheet = [];
        foreach (['defaultFontColor', 'defaultFontSize', 'defaultFontFamily', 'blockquoteBorderColor', 'bannerBackground'] as $k) if (isset($gs[$k])) $sheet[$k] = $gs[$k];

        $v3 = [
            'id' => $v2['id'] ?? null, 'version' => '3.0.0', 'meta' => $v2['meta'] ?? [],
            'stylesheet' => $sheet,
            'sections' => [['id' => (($v2['id'] ?? 'doc') . '::sec0'), 'type' => 'section', 'pageSetup' => $pageSetup, 'flow' => $flow]],
        ];
        foreach (['theme', 'emailSettings', 'variables', 'createdAt', 'updatedAt'] as $k) if (isset($v2[$k])) $v3[$k] = $v2[$k];
        return $v3;
    }

    // ─── v3 → v2 (ponte de render) ───────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function flowToColumnChildren(array $flow, callable $gen): array
    {
        $out = [];
        foreach ($flow ?? [] as $n) {
            if (self::isColumnSet($n)) $out[] = self::columnSetToStructure($n, $gen);
            elseif (self::isGroup($n)) $out[] = self::groupToStructure($n, $gen);
            else $out[] = $n;
        }
        return $out;
    }

    private static function columnSetToStructure(array $cs, callable $gen): array
    {
        $columns = [];
        foreach ($cs['columns'] ?? [] as $c) {
            // v3 ESPARSO: meta/styles/width podem faltar → preenche defaults (espelha
            // a normalização do editor), senão o render v2 a jusante quebra.
            $col = ['id' => $c['id'] ?? $gen(), 'width' => $c['width'] ?? 100, 'styles' => $c['styles'] ?? self::defaultStyles(), 'children' => self::flowToColumnChildren($c['flow'] ?? [], $gen)];
            if (isset($c['responsive'])) $col['responsive'] = $c['responsive'];
            $col = array_merge($col, self::carryOf($c));
            $columns[] = $col;
        }
        $st = ['id' => $cs['id'] ?? $gen(), 'type' => 'structure', 'meta' => $cs['meta'] ?? self::defaultMeta(), 'styles' => $cs['styles'] ?? self::defaultStyles(), 'columns' => $columns, 'columnGap' => $cs['columnGap'] ?? 0, 'verticalAlignment' => $cs['verticalAlignment'] ?? 'top'];
        if (isset($cs['itemScope'])) $st['itemScope'] = $cs['itemScope'];
        return array_merge($st, self::carryOf($cs));
    }

    private static function groupToStructure(array $g, callable $gen): array
    {
        $children = self::flowToColumnChildren($g['flow'] ?? [], $gen);
        $st = ['id' => $g['id'] ?? $gen(), 'type' => 'structure', 'meta' => $g['meta'] ?? self::defaultMeta(), 'styles' => $g['styles'] ?? self::defaultStyles(), 'columns' => [['id' => $gen(), 'width' => 100, 'styles' => self::defaultStyles(), 'children' => $children]], 'columnGap' => 0, 'verticalAlignment' => 'top'];
        $st = array_merge($st, self::carryOf($g));
        if (isset($g['itemScope'])) $st['itemScope'] = $g['itemScope'];
        if (($g['variant'] ?? null) === 'banner') {
            $st['variant'] = 'banner';
            foreach (['backgroundImage', 'backgroundSize', 'backgroundPosition', 'minHeight', 'fillHeight', 'overlayColor', 'overlayOpacity'] as $k) {
                if (isset($g['banner'][$k])) $st[$k] = $g['banner'][$k];
            }
        }
        return $st;
    }

    private static function leafStructure(array $leaves, callable $gen): array
    {
        return ['id' => $gen(), 'type' => 'structure', 'meta' => self::defaultMeta(), 'styles' => self::defaultStyles(), 'columns' => [['id' => $gen(), 'width' => 100, 'styles' => self::defaultStyles(), 'children' => $leaves]], 'columnGap' => 0, 'verticalAlignment' => 'top'];
    }

    /** @return list<array<string, mixed>> */
    private static function flowToStructures(array $flow, callable $gen): array
    {
        $out = [];
        $buffer = [];
        $flush = function () use (&$out, &$buffer, $gen) { if ($buffer) { $out[] = self::leafStructure($buffer, $gen); $buffer = []; } return $buffer; };
        foreach ($flow ?? [] as $n) {
            if (self::isColumnSet($n)) { $buffer = $flush(); $out[] = self::columnSetToStructure($n, $gen); }
            elseif (self::isGroup($n)) { $buffer = $flush(); $out[] = self::groupToStructure($n, $gen); }
            else $buffer[] = $n;
        }
        if ($buffer) $out[] = self::leafStructure($buffer, $gen);
        return $out;
    }

    private static function leafStripe(array $leaves, callable $gen): array
    {
        return ['id' => $gen(), 'type' => 'stripe', 'meta' => self::defaultMeta(), 'styles' => self::defaultStyles(), 'contentMaxWidth' => 0, 'contentAlignment' => 'center', 'children' => [self::leafStructure($leaves, $gen)]];
    }

    private static function groupToStripe(array $g, callable $gen): array
    {
        if (($g['variant'] ?? null) === 'banner') {
            return ['id' => $gen(), 'type' => 'stripe', 'meta' => self::defaultMeta(), 'styles' => self::defaultStyles(), 'contentMaxWidth' => 0, 'contentAlignment' => 'center', 'children' => [self::groupToStructure($g, $gen)]];
        }
        $stripe = ['id' => $g['id'] ?? $gen(), 'type' => 'stripe', 'meta' => $g['meta'] ?? self::defaultMeta(), 'styles' => $g['styles'] ?? self::defaultStyles(), 'contentMaxWidth' => $g['contentMaxWidth'] ?? 0, 'contentAlignment' => $g['contentAlignment'] ?? 'center', 'children' => self::flowToStructures($g['flow'] ?? [], $gen)];
        if (isset($g['itemScope'])) $stripe['itemScope'] = $g['itemScope'];

        return array_merge($stripe, self::carryOf($g));
    }

    /** @return list<array<string, mixed>> */
    private static function flowToStripes(array $flow, callable $gen): array
    {
        $stripes = [];
        $buffer = [];
        foreach ($flow ?? [] as $n) {
            if (self::isColumnSet($n)) {
                if ($buffer) { $stripes[] = self::leafStripe($buffer, $gen); $buffer = []; }
                $stripes[] = ['id' => $gen(), 'type' => 'stripe', 'meta' => self::defaultMeta(), 'styles' => self::defaultStyles(), 'contentMaxWidth' => 0, 'contentAlignment' => 'center', 'children' => [self::columnSetToStructure($n, $gen)]];
            } elseif (self::isGroup($n)) {
                if ($buffer) { $stripes[] = self::leafStripe($buffer, $gen); $buffer = []; }
                $stripes[] = self::groupToStripe($n, $gen);
            } else {
                $buffer[] = $n;
            }
        }
        if ($buffer) $stripes[] = self::leafStripe($buffer, $gen);
        return $stripes;
    }

    /**
     * Adaptador v3 → v2 (ponte de render). PURO/determinístico.
     *
     * @param  array<string, mixed>  $v3
     * @return array<string, mixed>
     */
    public static function v3ToV2(array $v3): array
    {
        $seed = $v3['id'] ?? 'v3';
        $counter = 0;
        $gen = function () use ($seed, &$counter) { return $seed . '::w' . $counter++; };

        $sections = $v3['sections'] ?? [];
        $ps = $sections[0]['pageSetup'] ?? [];
        // Multi-seção: cada seção após a 1ª começa em NOVA PÁGINA (break-before na
        // 1ª faixa). Chrome não suporta @page por seção; o pageSetup é o da 1ª seção
        // (compartilhado). Para 1 seção NADA muda → paridade preservada.
        $blocks = [];
        foreach ($sections as $i => $s) {
            $stripes = self::flowToStripes($s['flow'] ?? [], $gen);
            if ($i > 0 && count($stripes) > 0) {
                if (! isset($stripes[0]['meta']) || ! is_array($stripes[0]['meta'])) $stripes[0]['meta'] = [];
                $stripes[0]['meta']['breakBefore'] = true;
            }
            foreach ($stripes as $st) $blocks[] = $st;
        }

        $pageSettings = ['paperSize' => $ps['paperSize'] ?? null, 'orientation' => $ps['orientation'] ?? null, 'margins' => $ps['margins'] ?? null, 'defaultFontFamily' => $ps['defaultFontFamily'] ?? null];
        foreach (['pageMode', 'header', 'footer'] as $k) if (isset($ps[$k])) $pageSettings[$k] = $ps[$k];

        $sheet = $v3['stylesheet'] ?? [];
        $globalStyles = [
            'pageBackground' => $ps['pageBackground'] ?? '#ffffff',
            'contentBackground' => $ps['contentBackground'] ?? '#ffffff',
            'defaultFontColor' => $sheet['defaultFontColor'] ?? '#333333',
        ];
        if (isset($ps['contentWidth'])) $globalStyles['contentWidth'] = $ps['contentWidth'];
        foreach (['defaultFontFamily', 'defaultFontSize', 'blockquoteBorderColor', 'bannerBackground'] as $k) if (isset($sheet[$k])) $globalStyles[$k] = $sheet[$k];

        $v2 = [
            'id' => $v3['id'] ?? null, 'version' => '2.0.0', 'meta' => $v3['meta'] ?? [],
            'pageSettings' => $pageSettings, 'globalStyles' => $globalStyles,
            'blocks' => $blocks,
        ];
        foreach (['theme', 'emailSettings', 'variables', 'createdAt', 'updatedAt'] as $k) if (isset($v3[$k])) $v2[$k] = $v3[$k];
        return $v2;
    }

    /**
     * Envelope de PÁGINA do v3 → forma v2 SEM o corpo (blocks vazio): pageSettings,
     * globalStyles, meta, theme. Para o render NATIVO v3 (o corpo vem das Seções,
     * não achatado) reusar o "chrome" do document.blade (CSS/@page/header/footer).
     *
     * @param  array<string, mixed>  $v3
     * @return array<string, mixed>
     */
    /**
     * `pageSettings` EFETIVO de qualquer documento (v2 OU v3): no v3 a config de
     * página vive em `sections[0].pageSetup` — consumidores que leem
     * `document['pageSettings']` cru (ex.: drivers calculando o viewport)
     * enxergavam A4 default num payload v3 e mediam o layout na largura errada
     * (cauda gigante de fundo no PDF contínuo, ou corte com papel estreito).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function effectivePageSettings(array $document): array
    {
        if (self::isV3($document)) {
            return self::v3Envelope($document)['pageSettings'];
        }

        return is_array($document['pageSettings'] ?? null) ? $document['pageSettings'] : [];
    }

    public static function v3Envelope(array $v3): array
    {
        $sections = $v3['sections'] ?? [];
        $ps = $sections[0]['pageSetup'] ?? [];

        $pageSettings = ['paperSize' => $ps['paperSize'] ?? null, 'orientation' => $ps['orientation'] ?? null, 'margins' => $ps['margins'] ?? null, 'defaultFontFamily' => $ps['defaultFontFamily'] ?? null];
        foreach (['pageMode', 'header', 'footer'] as $k) if (isset($ps[$k])) $pageSettings[$k] = $ps[$k];

        $sheet = $v3['stylesheet'] ?? [];
        $globalStyles = [
            'pageBackground' => $ps['pageBackground'] ?? '#ffffff',
            'contentBackground' => $ps['contentBackground'] ?? '#ffffff',
            'defaultFontColor' => $sheet['defaultFontColor'] ?? '#333333',
        ];
        if (isset($ps['contentWidth'])) $globalStyles['contentWidth'] = $ps['contentWidth'];
        foreach (['defaultFontFamily', 'defaultFontSize', 'blockquoteBorderColor', 'bannerBackground'] as $k) if (isset($sheet[$k])) $globalStyles[$k] = $sheet[$k];

        $env = [
            'id' => $v3['id'] ?? null, 'version' => '2.0.0', 'meta' => $v3['meta'] ?? [],
            'pageSettings' => $pageSettings, 'globalStyles' => $globalStyles, 'blocks' => [],
        ];
        foreach (['theme', 'emailSettings', 'variables', 'createdAt', 'updatedAt'] as $k) if (isset($v3[$k])) $env[$k] = $v3[$k];
        return $env;
    }

    /** `true` se o documento está no modelo v3 (Seções). */
    public static function isV3(array $doc): bool
    {
        return ($doc['version'] ?? null) === '3.0.0' || isset($doc['sections']);
    }
}
