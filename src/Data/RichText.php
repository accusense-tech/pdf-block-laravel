<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Data;

/**
 * Texto RICO das variáveis `longtext` — ESPELHO EXATO de `data/richtext.ts`
 * (client). HTML restrito ao conjunto que o render entende nos dois lados:
 *  - inline: `<strong>/<b>`, `<em>/<i>`, `<u>`, `<br>`,
 *    `<span style="font-size: …">` (mark `textStyle`);
 *  - bloco:  `<p>`, `<ul>/<ol>/<li>` (listas, com aninhamento).
 * Tag desconhecida é descartada (o texto interno permanece); entidades básicas
 * decodificadas. Parser por TOKENS (regex), idêntico nos dois lados.
 *
 * Projeções: `toBlocks` (blocos TipTap — chip sozinho num parágrafo),
 * `toInlineNodes` (fragmento inline; listas degradam p/ linhas com marcador),
 * `toPlain` (texto puro).
 */
final class RichText
{
    // Casa QUALQUER tag; grupo 3 captura os atributos crus (font-size do span).
    private const TOKEN_RE = '/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)((?:\s[^>]*)?)\/?\s*>/';

    private const FONT_SIZE_RE = '/font-size:\s*([0-9.]+(?:px|pt|em|rem|%)?)/i';

    private const MARK_OF = [
        'strong' => 'bold', 'b' => 'bold',
        'em' => 'italic', 'i' => 'italic',
        'u' => 'underline',
    ];

    private static function decodeEntities(string $s): string
    {
        return str_replace(
            ['&nbsp;', '&lt;', '&gt;', '&quot;', '&#39;', '&amp;'],
            [' ', '<', '>', '"', "'", '&'],
            $s,
        );
    }

    /**
     * Marks efetivas da pilha: únicas por type (a MAIS INTERNA vence), na ordem
     * de abertura. Entradas com type null (span neutro) são ignoradas.
     *
     * @param  list<array{tag: string, type: ?string, attrs?: array<string, mixed>}>  $active
     * @return list<array<string, mixed>>
     */
    private static function marksOf(array $active): array
    {
        $order = [];
        $byType = [];
        foreach ($active as $m) {
            if (($m['type'] ?? null) === null) {
                continue;
            }
            if (! array_key_exists($m['type'], $byType)) {
                $order[] = $m['type'];
            }
            $byType[$m['type']] = $m;
        }
        $out = [];
        foreach ($order as $t) {
            $m = $byType[$t];
            $node = ['type' => $t];
            if (isset($m['attrs'])) {
                $node['attrs'] = $m['attrs'];
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * HTML restrito → BLOCOS TipTap (paragraph / bulletList / orderedList).
     *
     * @return list<array<string, mixed>>
     */
    public static function toBlocks(string $html): array
    {
        $blocks = [];
        // Pilha de listas abertas: cada frame = ['list' => &, 'item' => &|null].
        $stack = [];
        $active = [];
        $inline = [];

        // Anexa $node ao container corrente (item aberto do topo da pilha, senão topo).
        $append = function (array $node) use (&$blocks, &$stack): int {
            if ($stack !== []) {
                $top = &$stack[count($stack) - 1];
                if ($top['item'] !== null) {
                    $top['item']['content'][] = $node;

                    return count($top['item']['content']) - 1;
                }
            }
            $blocks[] = $node;

            return count($blocks) - 1;
        };
        $pushText = function (string $raw) use (&$inline, &$active): void {
            $text = self::decodeEntities($raw);
            if ($text === '') {
                return;
            }
            $node = ['type' => 'text', 'text' => $text];
            $marks = self::marksOf($active);
            if ($marks !== []) {
                $node['marks'] = $marks;
            }
            $inline[] = $node;
        };
        $flushInline = function () use (&$inline, $append): void {
            while ($inline !== [] && ($inline[count($inline) - 1]['type'] ?? '') === 'hardBreak') {
                array_pop($inline);
            }
            if ($inline === []) {
                return;
            }
            $append(['type' => 'paragraph', 'content' => $inline]);
            $inline = [];
        };
        // `listItem` exige ≥1 bloco — item fechado vazio ganha parágrafo vazio.
        $sealItem = function (?array &$item): void {
            if ($item !== null && $item['content'] === []) {
                $item['content'][] = ['type' => 'paragraph'];
            }
        };

        $last = 0;
        if (preg_match_all(self::TOKEN_RE, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $pushText(substr($html, $last, $m[0][1] - $last));
                $last = $m[0][1] + strlen($m[0][0]);
                $closing = $m[1][0] === '/';
                $tag = strtolower($m[2][0]);
                $attrsRaw = $m[3][0] ?? '';

                if ($tag === 'br') {
                    if (! $closing && $inline !== []) {
                        $inline[] = ['type' => 'hardBreak'];
                    }
                    continue;
                }
                if ($tag === 'p') {
                    $flushInline();
                    continue;
                }
                if ($tag === 'ul' || $tag === 'ol') {
                    $flushInline();
                    if ($closing) {
                        if ($stack !== []) {
                            $top = array_pop($stack);
                            $sealItem($top['item']);
                            if ($top['item'] !== null) {
                                $top['list']['content'][] = $top['item'];
                            }
                            // Lista fechada entra no container do PAI só agora
                            // (montagem bottom-up — PHP não tem referência barata).
                            if ($top['list']['content'] !== []) {
                                if ($stack !== []) {
                                    $parent = &$stack[count($stack) - 1];
                                    if ($parent['item'] !== null) {
                                        $parent['item']['content'][] = $top['list'];
                                    } else {
                                        $blocks[] = $top['list'];
                                    }
                                } else {
                                    $blocks[] = $top['list'];
                                }
                            }
                        }
                    } else {
                        $stack[] = [
                            'list' => ['type' => $tag === 'ul' ? 'bulletList' : 'orderedList', 'content' => []],
                            'item' => null,
                        ];
                    }
                    continue;
                }
                if ($tag === 'li') {
                    $flushInline();
                    if ($stack === []) {
                        continue; // <li> solto: vira separador de parágrafo
                    }
                    $top = &$stack[count($stack) - 1];
                    if ($closing) {
                        $sealItem($top['item']);
                        if ($top['item'] !== null) {
                            $top['list']['content'][] = $top['item'];
                            $top['item'] = null;
                        }
                        unset($top);
                        continue;
                    }
                    $sealItem($top['item']);
                    if ($top['item'] !== null) { // <li> aberto sem fechar o anterior
                        $top['list']['content'][] = $top['item'];
                    }
                    $top['item'] = ['type' => 'listItem', 'content' => []];
                    unset($top);
                    continue;
                }
                if ($tag === 'span') {
                    if ($closing) {
                        for ($i = count($active) - 1; $i >= 0; $i--) {
                            if ($active[$i]['tag'] === 'span') {
                                array_splice($active, $i, 1);
                                break;
                            }
                        }
                    } else {
                        // Span sem font-size entra NEUTRO (o fechamento casa certo).
                        if (preg_match(self::FONT_SIZE_RE, $attrsRaw, $fs)) {
                            $active[] = ['tag' => 'span', 'type' => 'textStyle', 'attrs' => ['fontSize' => $fs[1]]];
                        } else {
                            $active[] = ['tag' => 'span', 'type' => null];
                        }
                    }
                    continue;
                }
                $mark = self::MARK_OF[$tag] ?? null;
                if ($mark === null) {
                    continue; // tag desconhecida: descartada, texto permanece
                }
                if ($closing) {
                    for ($i = count($active) - 1; $i >= 0; $i--) {
                        if (($active[$i]['type'] ?? null) === $mark) {
                            array_splice($active, $i, 1);
                            break;
                        }
                    }
                } else {
                    $active[] = ['tag' => $tag, 'type' => $mark];
                }
            }
        }
        $pushText(substr($html, $last));
        $flushInline();
        // Frames não fechados: sela e sobe bottom-up até o topo.
        while ($stack !== []) {
            $top = array_pop($stack);
            $sealItem($top['item']);
            if ($top['item'] !== null) {
                $top['list']['content'][] = $top['item'];
            }
            if ($top['list']['content'] !== []) {
                if ($stack !== []) {
                    $parent = &$stack[count($stack) - 1];
                    if ($parent['item'] !== null) {
                        $parent['item']['content'][] = $top['list'];
                    } else {
                        $blocks[] = $top['list'];
                    }
                    unset($parent);
                } else {
                    $blocks[] = $top['list'];
                }
            }
        }

        return $blocks;
    }

    /**
     * `true` quando os blocos exigem contexto de BLOCO (lista ou 2+ parágrafos).
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public static function blocksAreBlockLevel(array $blocks): bool
    {
        if (count($blocks) > 1) {
            return true;
        }
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') !== 'paragraph') {
                return true;
            }
        }

        return false;
    }

    /**
     * HTML restrito → fragmento INLINE (text+marks / hardBreak). Parágrafos
     * viram quebras; LISTAS degradam para linhas com marcador ("• " / "1. ").
     *
     * @return list<array<string, mixed>>
     */
    public static function toInlineNodes(string $html): array
    {
        $out = [];
        $joinBreak = function () use (&$out): void {
            if ($out !== []) {
                $out[] = ['type' => 'hardBreak'];
            }
        };
        // Conteúdo do item colado ao marcador (sem quebra antes do 1º parágrafo).
        $walkItem = function (array $itemBlocks) use (&$out, $joinBreak, &$walkItem): void {
            $first = true;
            foreach ($itemBlocks as $b) {
                if (($b['type'] ?? '') === 'paragraph') {
                    if (($b['content'] ?? []) === []) {
                        continue;
                    }
                    if (! $first) {
                        $joinBreak();
                    }
                    foreach ($b['content'] as $n) {
                        $out[] = $n;
                    }
                    $first = false;
                    continue;
                }
                foreach ($b['content'] ?? [] as $i => $item) {
                    $joinBreak();
                    $out[] = ['type' => 'text', 'text' => ($b['type'] ?? '') === 'orderedList' ? ($i + 1) . '. ' : '• '];
                    $walkItem($item['content'] ?? []);
                    $first = false;
                }
            }
        };
        foreach (self::toBlocks($html) as $b) {
            if (($b['type'] ?? '') === 'paragraph') {
                if (($b['content'] ?? []) === []) {
                    continue;
                }
                $joinBreak();
                foreach ($b['content'] as $n) {
                    $out[] = $n;
                }
                continue;
            }
            foreach ($b['content'] ?? [] as $i => $item) {
                $joinBreak();
                $out[] = ['type' => 'text', 'text' => ($b['type'] ?? '') === 'orderedList' ? ($i + 1) . '. ' : '• '];
                $walkItem($item['content'] ?? []);
            }
        }
        while ($out !== [] && ($out[count($out) - 1]['type'] ?? '') === 'hardBreak') {
            array_pop($out);
        }

        return $out;
    }

    /** HTML restrito → texto puro (quebras viram "\n"; listas viram linhas com marcador). */
    public static function toPlain(string $html): string
    {
        $parts = [];
        foreach (self::toInlineNodes($html) as $node) {
            $parts[] = $node['type'] === 'hardBreak' ? "\n" : (string) ($node['text'] ?? '');
        }

        return implode('', $parts);
    }
}
