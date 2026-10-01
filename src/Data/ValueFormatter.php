<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Data;

/**
 * Formatação de valores por `format` da variável — espelho EXATO do
 * `packages/react/src/data/format.ts` (client).
 *
 * Aplicada ao resolver bindings, com base no `format` da variável/campo
 * (`VariableDef.format` / `VariableField.format`):
 *  - `date:dd/MM/yyyy` · `datetime:dd/MM/yyyy HH:mm`
 *  - `number:2` · `currency:BRL` · `percent:1`
 *
 * A formatação numérica é MANUAL (padrão pt-BR: milhar `.`, decimal `,`) e as
 * datas usam UTC — garantindo paridade com o client (sem `Intl`/fuso local).
 *
 * Nota de paridade: valores de data/hora SEM timezone devem vir como data pura
 * (`yyyy-mm-dd`) ou com `Z`/offset — o JS interpreta date-time sem fuso como
 * horário local, o que pode divergir do UTC assumido aqui.
 */
class ValueFormatter
{
    private const CURRENCY = ['BRL' => 'R$', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];

    /** Converte escalar em string; arrays/objetos → '' (espelha o JS `scalarString`). */
    public static function scalarString(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false'; // paridade com JS `String(bool)`
        }
        return (string) $value;
    }

    /** Aplica o `format` a um valor; sem `format`, retorna o valor escalar como string. */
    public static function format(mixed $value, ?string $format): string
    {
        if ($format === null || $format === '') {
            return self::scalarString($value);
        }
        [$kind, $arg] = self::split($format);
        return match ($kind) {
            'date'     => self::formatDate($value, $arg !== '' ? $arg : 'dd/MM/yyyy'),
            'datetime' => self::formatDate($value, $arg !== '' ? $arg : 'dd/MM/yyyy HH:mm'),
            'number'   => self::formatNumber($value, $arg !== '' ? (int) $arg : 0),
            'currency' => self::formatCurrency($value, $arg !== '' ? $arg : 'BRL'),
            'percent'  => self::formatNumber($value, $arg !== '' ? (int) $arg : 0) . '%',
            // Texto RICO (longtext): em contexto de STRING degrada p/ texto puro;
            // o fragmento rico só existe no nó variable (BindingResolver).
            'rich'     => RichText::toPlain(self::scalarString($value)),
            default    => self::scalarString($value),
        };
    }

    /** @return array{0: string, 1: string} */
    private static function split(string $format): array
    {
        $i = strpos($format, ':');
        return $i === false
            ? [trim($format), '']
            : [trim(substr($format, 0, $i)), trim(substr($format, $i + 1))];
    }

    private static function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_string($value) && trim($value) !== '') {
            $s = str_replace(',', '.', $value);
            return is_numeric($s) ? (float) $s : null;
        }
        return null;
    }

    private static function formatNumber(mixed $value, int $decimals): string
    {
        $n = self::toNumber($value);
        if ($n === null) {
            return self::scalarString($value);
        }
        $fixed = number_format(abs($n), max(0, $decimals), '.', '');
        [$int, $dec] = array_pad(explode('.', $fixed), 2, '');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $int) ?? $int;
        return ($n < 0 ? '-' : '') . $grouped . ($dec !== '' ? ',' . $dec : '');
    }

    private static function formatCurrency(mixed $value, string $code): string
    {
        $n = self::toNumber($value);
        if ($n === null) {
            return self::scalarString($value);
        }
        $symbol = self::CURRENCY[strtoupper($code)] ?? strtoupper($code);
        return $symbol . ' ' . self::formatNumber($n, 2);
    }

    /**
     * Meses pt-BR por extenso (paridade manual com o TS — sem Intl/locale do SO).
     *
     * @var list<string>
     */
    private const MONTHS_PT = [
        'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    private static function formatDate(mixed $value, string $pattern): string
    {
        $d = self::toDate($value);
        if ($d === null) {
            return self::scalarString($value);
        }
        $month = (int) $d->format('n') - 1;
        $map = [
            'yyyy' => $d->format('Y'),
            'yy'   => $d->format('y'),
            'MMMM' => self::MONTHS_PT[$month],
            'MMM'  => mb_substr(self::MONTHS_PT[$month], 0, 3),
            'MM'   => $d->format('m'),
            'dd'   => $d->format('d'),
            'd'    => $d->format('j'),
            'HH'   => $d->format('H'),
            'mm'   => $d->format('i'),
            'ss'   => $d->format('s'),
        ];
        // Trechos entre 'aspas simples' são LITERAIS (ex.: d 'de' MMMM 'de' yyyy).
        // Tokens maiores primeiro (MMMM antes de MM; dd antes de d) — espelha o TS.
        return preg_replace_callback(
            "/'([^']*)'|yyyy|yy|MMMM|MMM|MM|dd|d|HH|mm|ss/",
            fn (array $m) => isset($m[1]) && $m[1] !== '' ? $m[1] : ($m[0] === "''" ? '' : ($map[$m[0]] ?? $m[0])),
            $pattern,
        ) ?? $pattern;
    }

    private static function toDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }
        if (is_int($value) || is_float($value)) {
            // JS `new Date(number)` = milissegundos desde a epoch.
            return (new \DateTimeImmutable('@' . (int) floor(((float) $value) / 1000)))->setTimezone(new \DateTimeZone('UTC'));
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return null;
            }
        }
        return null;
    }
}
