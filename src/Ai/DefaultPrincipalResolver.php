<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Ai;

use Illuminate\Http\Request;
use PdfBlock\Laravel\Contracts\AiPrincipalResolver;

/**
 * Resolver padrão: o usuário autenticado da requisição, só pelo identificador.
 *
 * O id sai SEMPRE como string (a chave pode ser inteira ou uuid), para o
 * servidor do chat compará-lo sem se preocupar com o tipo.
 */
final class DefaultPrincipalResolver implements AiPrincipalResolver
{
    public function resolve(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return ['id' => (string) $user->getAuthIdentifier()];
    }
}
