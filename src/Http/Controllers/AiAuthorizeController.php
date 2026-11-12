<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PdfBlock\Laravel\Contracts\AiPrincipalResolver;

/**
 * `GET {pdf-block.ai.authorize.path}` — a rota que o servidor do chat de IA
 * consulta, com o Cookie/Authorization do navegador, para saber quem é a pessoa.
 *
 * `200` com o principal que o {@see AiPrincipalResolver} devolver, ou `401` com
 * `{"error":"não autenticado"}` quando não houver identidade. Só é registrada
 * com `pdf-block.ai.authorize.enabled` ligado (ver o provider).
 */
final class AiAuthorizeController
{
    public function __invoke(Request $request, AiPrincipalResolver $resolver): JsonResponse
    {
        $principal = $resolver->resolve($request);

        if ($principal === null) {
            // Sem escapar o unicode: o corpo sai legível, exatamente como documentado.
            return response()->json(['error' => 'não autenticado'], 401, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json($principal);
    }
}
