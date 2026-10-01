<?php

declare(strict_types=1);

namespace PdfBlock\Laravel;

use PdfBlock\Laravel\Contracts\PdfDriver;
use PdfBlock\Laravel\Data\DataBindingExpander;
use PdfBlock\Laravel\Data\InlineThemeColorResolver;
use PdfBlock\Laravel\Data\ActiveThemeProjector;
use PdfBlock\Laravel\Data\ThemeResolver;
use PdfBlock\Laravel\Data\DocumentVariables;

/**
 * Ponto de entrada principal — converte um documento da DSL pdf-block em HTML ou PDF.
 *
 * Uso:
 *   $renderer = app(PdfBlockRenderer::class);
 *   $html = $renderer->toHtml($document);
 *   $pdf  = $renderer->toPdf($document);
 *   return $pdf->toResponse('fatura.pdf');
 */
class PdfBlockRenderer
{
    public function __construct(
        private readonly PdfDriver $driver,
        private readonly TiptapConverter $tiptap,
        private readonly array $config = [],
    ) {
    }

    /**
     * Renderiza o documento DSL como string HTML completa (página standalone).
     *
     * Útil para debug, e-mail ou alimentar qualquer pipeline HTML → PDF.
     */
    public function toHtml(array $document, array $opts = []): string
    {
        // Dados = variáveis do DOCUMENTO + overrides de runtime do host (vencem) +
        // automáticas sys.* — o documento carrega o próprio "formulário".
        $overrides = is_array($opts['data'] ?? null) ? $opts['data'] : [];
        $data = DocumentVariables::buildData($document, $overrides);
        $formatMap = DocumentVariables::formatMap($document);

        // v3 (Seções/fluxo) é a fonte da verdade do PDF e renderiza NATIVAMENTE —
        // SEM achatar para v2 (a conversão v2→v3 acontece só no load). v3ToV2 fica
        // como ponte apenas do e-mail (congelado v2, D3).
        $isV3 = DocumentMigrator::isV3($document);

        // Resolve os tokens de tema `{{token:...}}` contra `document['theme']` ANTES
        // dos bindings. Ambos os passos operam no v2 OU no v3 nativo (ThemeResolver
        // é agnóstico; DataBindingExpander percorre sections→flow no v3).
        // Tema ATIVO (themes[]/activeThemeId) → theme.colors ANTES de resolver os
        // tokens: documentos montados fora do editor costumam trazer só `themes`,
        // e sem esta projeção nenhum {{token:colors.*}} resolveria.
        $document = ActiveThemeProjector::project($document);
        $document = ThemeResolver::resolve($document);
        // Re-veste cor de texto inline (themeColor→color) contra a paleta ativa.
        $document = InlineThemeColorResolver::resolve($document);
        $document = DataBindingExpander::expand($document, $data, $formatMap);

        if ($isV3) {
            // Resolve estilos NOMEADOS (styleRef) ANTES do blade: mescla cada nome
            // sobre os styles do bloco e remove o styleRef (seções ficam "flat").
            $sections = $document['sections'] ?? [];
            $namedStyles = $document['stylesheet']['namedStyles'] ?? null;
            if (is_array($namedStyles) && $namedStyles) {
                $sections = FlowRender::resolveStyleRefs($sections, $namedStyles);
            }

            // Headings (para os blocos TOC) — compartilhados com todos os blades.
            view()->share('pdfbTocHeadings', FlowRender::collectHeadings($sections));
            // Alvos de âncora de clique (link/botão → `#<blockId>`) — o wrapper do
            // bloco-alvo emite `id="<blockId>"` para o link interno funcionar no PDF.
            view()->share('pdfbAnchorTargets', FlowRender::collectAnchorTargets($sections));

            // O envelope (pageSettings/globalStyles/meta) alimenta o "chrome" do
            // document.blade; o CORPO vem das Seções, render nativo (não achatado).
            // Motor de paginação (seam de coexistência — ver HANDOFF-PAGINATOR.md).
            $paginationEngine = $opts['paginationEngine'] ?? config('pdf-block.pagination.engine', 'css');
            if (! in_array($paginationEngine, ['css', 'js'], true)) {
                $paginationEngine = 'css';
            }
            // Cabeçalho/rodapé document-level (FurnitureComponent v3 com `flow`) só é
            // MATERIALIZADO no PDF pelo motor 'js' (o 'css' usa @page margin-boxes /
            // @page nomeada, que renderizam apenas texto de margin-box — não o flow
            // rico do componente). Se o documento tem mobília habilitada com flow, força
            // 'js' automaticamente; senão o cabeçalho/rodapé some no PDF (default = css).
            if ($paginationEngine !== 'js' && self::hasDocumentFurniture($document)) {
                $paginationEngine = 'js';
            }

            return view('pdf-block::document', [
                'doc'              => DocumentMigrator::v3Envelope($document),
                'sectionsV3'       => $sections,
                'pageLayouts'      => $document['stylesheet']['pageLayouts'] ?? [],
                'paginationEngine' => $paginationEngine,
                'furnitureHeader'  => $document['header'] ?? null,
                'furnitureFooter'  => $document['footer'] ?? null,
                'isV3'             => true,
                'tiptap'           => $this->tiptap,
                'data'             => $data,
            ])->render();
        }

        return view('pdf-block::document', [
            'doc'    => $document,
            'tiptap' => $this->tiptap,
            'data'   => $data,
        ])->render();
    }

    /**
     * Renderiza o documento DSL como PDF nativo usando o driver configurado.
     *
     * Retorna um PdfResult que pode ser salvo, baixado ou transmitido.
     *
     * @param  array{data?: array<string, mixed>}  $opts
     */
    public function toPdf(array $document, array $opts = []): PdfResult
    {
        $pdf = $this->driver->render($this->toHtml($document, $opts), $document);

        return new PdfResult($pdf);
    }

    /**
     * `true` quando o documento tem cabeçalho OU rodapé document-level (v3)
     * habilitado com `flow` não-vazio — a mobília rica que só o motor 'js' renderiza.
     *
     * @param  array<string, mixed>  $document
     */
    private static function hasDocumentFurniture(array $document): bool
    {
        foreach (['header', 'footer'] as $slot) {
            $furniture = $document[$slot] ?? null;
            if (is_array($furniture) && ($furniture['enabled'] ?? false) && ! empty($furniture['flow'])) {
                return true;
            }
        }

        return false;
    }
}


