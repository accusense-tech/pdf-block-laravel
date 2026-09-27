<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Devolve ao input do request as chaves de DOCUMENTO lidas do CORPO CRU.
 *
 * ── O problema ───────────────────────────────────────────────────────────────
 * O Laravel roda `TrimStrings` e `ConvertEmptyStringsToNull` como middleware
 * GLOBAL (padrão do framework). Os dois percorrem o corpo do request
 * RECURSIVAMENTE e mexem em toda string — inclusive em cada nó `text` do JSON
 * do TipTap, onde o espaço é CONTEÚDO, não formatação. O estrago ao salvar:
 *
 *   {"type":"text","text":"palavra "} → "palavra"   → cola na palavra seguinte
 *                                                      ("palavra**negrito**")
 *   {"type":"text","text":"   ideia"} → "ideia"     → some o recuo do parágrafo
 *   {"type":"text","text":"   "}      → "" → null   → nó INVÁLIDO: o ProseMirror
 *                                                      lança ao abrir e o BLOCO
 *                                                      DE TEXTO SOME da página
 *
 * O dano é PERMANENTE: quem salva grava o texto já mutilado.
 *
 * ── A correção ───────────────────────────────────────────────────────────────
 * Este middleware roda DEPOIS dos globais e reescreve as chaves indicadas com o
 * valor original, relido de `$request->getContent()` (que os middlewares não
 * tocam). O `$request->validate()` do controller passa a ver o documento
 * intacto. Nada mais do payload muda: `name`, `description` etc. seguem sendo
 * aparados normalmente.
 *
 * ── Uso ──────────────────────────────────────────────────────────────────────
 * O alias `pdf-block.raw` é registrado pelo PdfBlockServiceProvider:
 *
 *   Route::prefix('pdf-block')->middleware('pdf-block.raw:document,block')->group(...)
 *
 * Sem argumento, protege `document`. Passe as chaves do seu payload — nos apps
 * que consomem o editor são `document` (templates/export) e `block` (módulos).
 * Ver docs/CONSUMIDORES.md.
 */
final class PreserveDocumentWhitespace
{
    /** Chaves protegidas quando a rota não passa nenhuma. */
    private const DEFAULT_KEYS = ['document'];

    public function handle(Request $request, Closure $next, string ...$keys): mixed
    {
        $keys = $keys === [] ? self::DEFAULT_KEYS : $keys;

        foreach ($this->rawKeys($request, $keys) as $key => $value) {
            $request->merge([$key => $value]);
        }

        return $next($request);
    }

    /**
     * Valores CRUS das chaves pedidas, só para as que existem mesmo no corpo.
     *
     * Restaurar uma chave AUSENTE (como `null`) transformaria um `sometimes` do
     * validador num campo presente e nulo — quebraria o PUT parcial.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function rawKeys(Request $request, array $keys): array
    {
        if (! $request->isJson()) {
            return [];
        }

        $content = $request->getContent();

        if (! is_string($content) || $content === '') {
            return [];
        }

        $body = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($body)) {
            return [];
        }

        $out = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $body)) {
                $out[$key] = $body[$key];
            }
        }

        return $out;
    }
}
