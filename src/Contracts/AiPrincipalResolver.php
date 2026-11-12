<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Contracts;

use Illuminate\Http\Request;

/**
 * Diz ao servidor do chat de IA QUEM é a pessoa que fez o pedido.
 *
 * O servidor Node repassa o `Cookie`/`Authorization` do navegador para a rota
 * `pdf-block.ai.authorize`, que chama este contrato. O host troca a regra
 * registrando a própria implementação no container:
 *
 *   $this->app->bind(AiPrincipalResolver::class, MeuResolver::class);
 *
 * O array devolvido é opaco para o pacote: `id` (string) identifica a pessoa e
 * os demais campos, se houver, viajam como vieram.
 */
interface AiPrincipalResolver
{
    /**
     * @return array<string, mixed>|null  O principal, ou `null` quando não há identidade (vira 401).
     */
    public function resolve(Request $request): ?array;
}
