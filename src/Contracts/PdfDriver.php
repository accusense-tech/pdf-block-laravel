<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Contracts;

/**
 * Contrato para drivers de geração de PDF.
 *
 * Cada driver recebe o HTML já renderizado e a DSL do documento (para
 * extrair configurações de papel, margens, orientação) e deve retornar
 * o conteúdo binário do PDF gerado.
 */
interface PdfDriver
{
    /**
     * Gera um PDF a partir do HTML e da DSL do documento.
     *
     * @param  string  $html      HTML completo gerado pelo PdfBlockRenderer::toHtml()
     * @param  array   $document  DSL do documento (usado para pageSettings)
     * @return string             Conteúdo binário do PDF
     *
     * @throws \RuntimeException  Se a geração do PDF falhar
     */
    public function render(string $html, array $document): string;
}
