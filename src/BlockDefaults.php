<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

require_once __DIR__ . '/SchemaDefaults.gen.php';

/**
 * Defaults dos CAMPOS PRÓPRIOS de cada tipo de bloco (não `id`/`meta`/`styles`).
 *
 * FONTE ÚNICA de paridade editor↔PDF: os valores vêm de `SchemaDefaults` (gerado
 * de packages/schema/defaults.json via `pnpm gen`), espelhado em TS por
 * `DEFAULT_OWN_FIELDS` (core/schema.gen.ts). Mantê-los idênticos é o que garante
 * que a DSL ESPARSA (bloco que omite um campo igual ao default) renderize no PDF
 * exatamente como no editor. Esta classe mantém a API estável (`all`/`fill`/…)
 * e apenas DELEGA os dados ao artefato gerado.
 *
 * NÃO inclui conteúdo "pesado" obrigatório (text.content, table.rows,
 * chart.data) — esses devem sempre vir no documento. Sentinelas de herança
 * (text/table.fontSize=0, fontColor='') são mantidas como tais.
 */
class BlockDefaults
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return SchemaDefaults::ownFields();
    }

    /** Campos próprios de uma `structure` com `variant: "banner"`. */
    public static function bannerFields(): array
    {
        return SchemaDefaults::bannerFields();
    }

    /** @return array<string, mixed> */
    public static function ownFields(string $type): array
    {
        return self::all()[$type] ?? [];
    }

    /**
     * Preenche os campos próprios AUSENTES do bloco com os defaults do tipo
     * (os valores explícitos do bloco sempre prevalecem). Tipos desconhecidos
     * (plugins) voltam inalterados.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function fill(array $block): array
    {
        $type = $block['type'] ?? '';
        $defaults = self::ownFields((string) $type);

        return $defaults ? array_merge($defaults, $block) : $block;
    }
}
