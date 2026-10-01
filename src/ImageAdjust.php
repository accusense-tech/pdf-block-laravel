<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

/**
 * AJUSTE DE IMAGEM — recorte (forma), enquadramento e cor.
 *
 * Espelho EXATO de `core/imageAdjust.ts` (mesmas fórmulas, mesmos defaults): a
 * forma (preset ou desenhada à mão) é `clip-path`, os ajustes de cor são
 * `filter` e o enquadramento é `background-position`/`object-position`.
 * O que o editor mostra no canvas é o que o Chromium imprime.
 */
final class ImageAdjust
{
    private static function clamp(float $n, float $min, float $max): float
    {
        return min($max, max($min, $n));
    }

    /** Intensidade normalizada (0..1); ausente = meio-termo. */
    private static function amount(array $adjust): float
    {
        return self::clamp((float) ($adjust['shapeAmount'] ?? 50), 0, 100) / 100;
    }

    /** `clip-path` da forma, ou string vazia quando não há recorte. */
    public static function clipPath(?array $adjust): string
    {
        $shape = $adjust['shape'] ?? 'none';
        if (! is_array($adjust) || $shape === 'none' || $shape === null) {
            return '';
        }
        $a = self::amount($adjust);
        $flip = ($adjust['shapeFlip'] ?? false) === true;

        return match ($shape) {
            'custom' => self::polygon($adjust['points'] ?? null, (float) ($adjust['smooth'] ?? 0)),
            'circle' => 'circle(' . number_format(50 + (1 - $a) * 20, 2, '.', '') . '% at 50% 50%)',
            'arch' => 'ellipse(100% ' . number_format(100 + (1 - $a) * 260, 0, '.', '') . '% at '
                . ($flip ? '100%' : '0%') . ' 50%)',
            'diagonal' => $flip
                ? 'polygon(0 0, 100% 0, 100% 100%, 0 ' . number_format($a * 60, 1, '.', '') . '%)'
                : 'polygon(0 0, 100% 0, 100% ' . number_format(100 - $a * 60, 1, '.', '') . '%, 0 100%)',
            'slant' => $flip
                ? 'polygon(0 ' . number_format($a * 30, 1, '.', '') . '%, 100% 0, 100% 100%, 0 100%)'
                : 'polygon(0 0, 100% ' . number_format($a * 30, 1, '.', '') . '%, 100% 100%, 0 100%)',
            'chevron' => $flip
                ? 'polygon(' . number_format($a * 25, 1, '.', '') . '% 0, 100% 0, 100% 100%, '
                    . number_format($a * 25, 1, '.', '') . '% 100%, 0 50%)'
                : 'polygon(0 0, ' . number_format(100 - $a * 25, 1, '.', '') . '% 0, 100% 50%, '
                    . number_format(100 - $a * 25, 1, '.', '') . '% 100%, 0 100%)',
            default => '',
        };
    }

    /** Mínimo/máximo de vértices do recorte livre (espelha o TS). */
    public const MIN_POINTS = 3;

    public const MAX_POINTS = 16;

    /** Segmentos por canto ao arredondar (espelha SMOOTH_STEPS no TS). */
    private const SMOOTH_STEPS = 6;

    /**
     * Arredonda os vértices: cada canto vira uma curva quadrática amostrada em
     * poucos segmentos, ainda dentro do `polygon()`. Espelho de `roundCorners`.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<array{0: float, 1: float}>
     */
    private static function roundCorners(array $points, float $smooth): array
    {
        $n = count($points);
        if ($n < self::MIN_POINTS) {
            return $points;
        }
        // Raio POR CANTO: o valor do próprio ponto vence o geral.
        $radii = [];
        $any = false;
        foreach ($points as $p) {
            $k = self::clamp((float) ($p[2] ?? $smooth), 0, 100) / 100;
            $radii[] = $k;
            $any = $any || $k > 0;
        }
        if (! $any) {
            return $points;
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $k = $radii[$i];
            [$vx, $vy] = $points[$i];
            if ($k <= 0) {
                $out[] = [$vx, $vy];   // canto reto: um ponto só
                continue;
            }
            [$px, $py] = $points[($i - 1 + $n) % $n];
            [$nx, $ny] = $points[($i + 1) % $n];
            $dPrev = hypot($vx - $px, $vy - $py) ?: 1;
            $dNext = hypot($nx - $vx, $ny - $vy) ?: 1;
            $r = ($k * min($dPrev, $dNext)) / 2;
            $ax = $vx + (($px - $vx) / $dPrev) * $r;
            $ay = $vy + (($py - $vy) / $dPrev) * $r;
            $bx = $vx + (($nx - $vx) / $dNext) * $r;
            $by = $vy + (($ny - $vy) / $dNext) * $r;
            for ($s = 0; $s <= self::SMOOTH_STEPS; $s++) {
                $t = $s / self::SMOOTH_STEPS;
                $u = 1 - $t;
                $out[] = [
                    $u * $u * $ax + 2 * $u * $t * $vx + $t * $t * $bx,
                    $u * $u * $ay + 2 * $u * $t * $vy + $t * $t * $by,
                ];
            }
        }

        return $out;
    }

    /** `polygon(...)` a partir dos vértices em % (`[[x, y], …]`). */
    public static function polygon(?array $points, float $smooth = 0): string
    {
        if (! is_array($points) || count($points) < self::MIN_POINTS) {
            return '';
        }
        $clean = [];
        foreach (array_slice($points, 0, self::MAX_POINTS) as $p) {
            if (! is_array($p) || count($p) < 2 || ! is_numeric($p[0]) || ! is_numeric($p[1])) {
                return '';
            }
            $clean[] = isset($p[2]) && is_numeric($p[2])
                ? [(float) $p[0], (float) $p[1], (float) $p[2]]
                : [(float) $p[0], (float) $p[1]];
        }
        $parts = [];
        foreach (self::roundCorners($clean, $smooth) as $p) {
            $parts[] = number_format(self::clamp($p[0], 0, 100), 2, '.', '') . '% '
                . number_format(self::clamp($p[1], 0, 100), 2, '.', '') . '%';
        }

        return 'polygon(' . implode(', ', $parts) . ')';
    }

    /** `filter` dos ajustes de cor, ou string vazia. */
    public static function filter(?array $adjust): string
    {
        if (! is_array($adjust)) {
            return '';
        }
        $parts = [];
        if (isset($adjust['brightness']) && (float) $adjust['brightness'] !== 100.0) {
            $parts[] = 'brightness(' . self::clamp((float) $adjust['brightness'], 0, 300) . '%)';
        }
        if (isset($adjust['contrast']) && (float) $adjust['contrast'] !== 100.0) {
            $parts[] = 'contrast(' . self::clamp((float) $adjust['contrast'], 0, 300) . '%)';
        }
        if (isset($adjust['saturate']) && (float) $adjust['saturate'] !== 100.0) {
            $parts[] = 'saturate(' . self::clamp((float) $adjust['saturate'], 0, 300) . '%)';
        }
        if (! empty($adjust['grayscale'])) {
            $parts[] = 'grayscale(' . self::clamp((float) $adjust['grayscale'], 0, 100) . '%)';
        }
        if (! empty($adjust['blur'])) {
            $parts[] = 'blur(' . self::clamp((float) $adjust['blur'], 0, 40) . 'px)';
        }

        return $parts ? implode(' ', $parts) : '';
    }

    /** Foco (`background-position`/`object-position`), ou string vazia. */
    public static function focus(?array $adjust): string
    {
        if (! is_array($adjust) || (! isset($adjust['focusX']) && ! isset($adjust['focusY']))) {
            return '';
        }

        return self::clamp((float) ($adjust['focusX'] ?? 50), 0, 100) . '% '
            . self::clamp((float) ($adjust['focusY'] ?? 50), 0, 100) . '%';
    }

    /** Zoom (>100 aproxima); 0 = sem zoom. */
    public static function zoom(?array $adjust): float
    {
        $z = is_array($adjust) ? ($adjust['zoom'] ?? null) : null;

        return ($z === null || (float) $z === 100.0) ? 0.0 : self::clamp((float) $z, 100, 400);
    }

    /** CSS inline pronto (clip-path + filter + zoom ancorado no foco). */
    public static function css(?array $adjust): string
    {
        $clip = self::clipPath($adjust);
        $filter = self::filter($adjust);
        $zoom = self::zoom($adjust);
        $focus = self::focus($adjust);

        return ($clip !== '' ? "clip-path:{$clip};" : '')
            . ($filter !== '' ? "filter:{$filter};" : '')
            . ($zoom > 0
                ? 'transform:scale(' . ($zoom / 100) . ');transform-origin:' . ($focus !== '' ? $focus : 'center') . ';'
                : '');
    }

    /** O ajuste muda alguma coisa? (senão não vale emitir a camada de fundo) */
    public static function isActive(?array $adjust): bool
    {
        return self::css($adjust) !== '' || self::focus($adjust) !== '';
    }
}
