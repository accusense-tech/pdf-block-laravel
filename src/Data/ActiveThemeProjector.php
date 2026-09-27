<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Data;

/**
 * Projeta o TEMA ATIVO (`themes[]` + `activeThemeId`) em `document['theme']` —
 * espelho de `projectActiveTheme` (packages/react/src/core/themes.ts), que o
 * editor aplica no load e a cada mutação.
 *
 * Por que o servidor precisa disso: `themes[]` é a fonte da verdade das cores,
 * mas quem resolve `{{token:colors.*}}` é o `ThemeResolver`, que lê SÓ
 * `document['theme']`. Um documento montado fora do editor (gerado por LLM/skill,
 * API, template versionado à mão) costuma declarar `themes` + `activeThemeId` e
 * deixar `theme` vazio ou defasado — o tema "existe", mas nenhum token resolve e
 * o PDF sai sem as cores. Projetar aqui torna o render independente de quem
 * montou o JSON, e mantém a invariante `theme.colors === (tema ativo).colors`.
 *
 * Só age quando há `themes[]`; `spacing`/`radius` (que não pertencem aos temas
 * nomeados) são preservados.
 */
class ActiveThemeProjector
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function project(array $document): array
    {
        $themes = $document['themes'] ?? null;
        if (! is_array($themes) || $themes === []) {
            return $document;
        }

        $active = self::activeTheme($themes, $document['activeThemeId'] ?? null);
        if ($active === null) {
            return $document;
        }

        $theme = is_array($document['theme'] ?? null) ? $document['theme'] : [];
        $colors = $active['colors'] ?? null;
        $theme['colors'] = is_array($colors) ? $colors : [];

        $gradients = $active['gradients'] ?? null;
        if (is_array($gradients) && $gradients !== []) {
            $theme['gradients'] = $gradients;
        } else {
            unset($theme['gradients']);
        }

        $document['theme'] = $theme;

        return $document;
    }

    /**
     * Tema ativo por id, com fallback no primeiro (igual ao `getActiveTheme`).
     *
     * @param  array<int, mixed>  $themes
     * @return array<string, mixed>|null
     */
    private static function activeTheme(array $themes, mixed $activeId): ?array
    {
        if (is_string($activeId) && $activeId !== '') {
            foreach ($themes as $t) {
                if (is_array($t) && ($t['id'] ?? null) === $activeId) {
                    return $t;
                }
            }
        }
        foreach ($themes as $t) {
            if (is_array($t)) {
                return $t;
            }
        }

        return null;
    }
}
