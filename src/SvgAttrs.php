<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

/**
 * Aparência do bloco `svg` aplicada como ATRIBUTOS do elemento `<svg>` raiz —
 * espelho de `applySvgAppearance` em packages/react/src/utils/svg.ts
 * (paridade editor↔PDF).
 *
 * `fill`, `stroke`, `stroke-width` e `color` são propriedades HERDADAS em SVG:
 * declaradas no raiz, valem para todo elemento que não define a sua. É assim que
 * se recolore um ícone sem tocar nos paths — e não depende de folha de estilo
 * (o Blade só tem `style` inline). O que o desenho já declara continua vencendo.
 */
class SvgAttrs
{
    private const PRESERVE = [
        'contain' => 'xMidYMid meet',
        'cover' => 'xMidYMid slice',
        'fill' => 'none',
    ];

    /** @param array<string, mixed> $block */
    public static function apply(string $svg, array $block): string
    {
        $s = trim($svg);
        if ($s === '') {
            return '';
        }

        $attrs = [];
        foreach (['fill' => 'fill', 'stroke' => 'stroke', 'color' => 'color'] as $key => $attr) {
            $v = $block[$key] ?? null;
            if (is_string($v) && $v !== '') {
                $attrs[$attr] = self::escapeAttr($v);
            }
        }
        if (isset($block['strokeWidth']) && is_numeric($block['strokeWidth'])) {
            $attrs['stroke-width'] = (string) ($block['strokeWidth'] + 0);
        }
        $fit = $block['fit'] ?? null;
        if (is_string($fit) && isset(self::PRESERVE[$fit])) {
            $attrs['preserveAspectRatio'] = self::PRESERVE[$fit];
        }
        if ($attrs === []) {
            return $s;
        }

        $rendered = [];
        foreach ($attrs as $name => $value) {
            $rendered[] = $name.'="'.$value.'"';
        }

        // Injeta logo após `<svg`; os homônimos já presentes no raiz saem antes
        // (num atributo repetido, o PRIMEIRO valor é o que vale no HTML).
        return preg_replace_callback(
            '#<svg\b([^>]*)>#i',
            static function (array $m) use ($attrs, $rendered): string {
                $keep = $m[1];
                foreach (array_keys($attrs) as $name) {
                    $keep = preg_replace(
                        '#\s'.preg_quote($name, '#').'\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
                        '',
                        $keep
                    ) ?? $keep;
                }

                return '<svg '.implode(' ', $rendered).$keep.'>';
            },
            $s,
            1
        ) ?? $s;
    }

    /** `transform` CSS do wrapper (rotação/espelho) — '' quando não há nada. */
    /** @param array<string, mixed> $block */
    public static function transform(array $block): string
    {
        $parts = [];
        $rotate = $block['rotate'] ?? null;
        if (is_numeric($rotate) && (float) $rotate !== 0.0) {
            $parts[] = 'rotate('.($rotate + 0).'deg)';
        }
        $flipH = ! empty($block['flipH']);
        $flipV = ! empty($block['flipV']);
        if ($flipH || $flipV) {
            $parts[] = 'scale('.($flipH ? '-1' : '1').', '.($flipV ? '-1' : '1').')';
        }

        return implode(' ', $parts);
    }

    private static function escapeAttr(string $v): string
    {
        return str_replace(['"', '<', '>'], ['&quot;', '&lt;', '&gt;'], $v);
    }
}
