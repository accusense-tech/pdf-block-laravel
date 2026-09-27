# Início Rápido — pdf-block/laravel

Guia prático para gerar PDFs a partir de documentos criados no editor `@pdf-block/react`.

## Pré-requisitos

- [Instalação](installation.md) concluída (pacote + driver escolhido: `local` ou `browserless`)
- Editor React enviando a DSL JSON via API

---

## 1. Criar Rota de Export

```php
// routes/api.php
use Illuminate\Http\Request;
use PdfBlock\Laravel\PdfBlockRenderer;

Route::post('/export/pdf', function (Request $request, PdfBlockRenderer $renderer) {
    $document = $request->input('document');

    return $renderer->toPdf($document)->toResponse('documento.pdf');
});
```

---

## 2. Chamar do React

```tsx
import { useRef } from 'react';
import { PDFBuilder } from '@pdf-block/react';
import type { PDFBuilderRef } from '@pdf-block/react';

function App() {
  const editorRef = useRef<PDFBuilderRef>(null);

  const exportPdf = async () => {
    const doc = editorRef.current?.getDocument();
    if (!doc) return;

    const res = await fetch('/api/export/pdf', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ document: doc }),
    });

    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    window.open(url);
  };

  return (
    <PDFBuilder
      ref={editorRef}
      toolbarActions={() => (
        <button onClick={exportPdf}>Exportar PDF</button>
      )}
    />
  );
}
```

---

## 3. API do PdfBlockRenderer

### Injeção de Dependência

```php
use PdfBlock\Laravel\PdfBlockRenderer;

class ExportController extends Controller
{
    public function __construct(
        private PdfBlockRenderer $renderer,
    ) {}

    public function pdf(Request $request)
    {
        $document = $request->input('document');
        return $this->renderer->toPdf($document)->toResponse('export.pdf');
    }

    public function html(Request $request)
    {
        $document = $request->input('document');
        return $this->renderer->toHtml($document);
    }
}
```

### Via Service Container

```php
$renderer = app(PdfBlockRenderer::class);
// ou
$renderer = app('pdf-block');
```

### Métodos

| Método | Retorno | Descrição |
|---|---|---|
| `toHtml($document)` | `string` | Renderiza a DSL como HTML standalone |
| `toPdf($document)` | `PdfResult` | Renderiza a DSL como PDF nativo |

---

## 4. PdfResult — API Completa

O `toPdf()` retorna um objeto `PdfResult` com métodos para consumir o PDF:

```php
$pdf = $renderer->toPdf($document);

// Download (Content-Disposition: attachment)
return $pdf->toResponse('fatura.pdf');

// Preview inline no browser (Content-Disposition: inline)
return $pdf->toInlineResponse('preview.pdf');

// Salvar em disco
$pdf->save(storage_path('exports/fatura.pdf'));

// Conteúdo binário raw
$binary = $pdf->content();

// Tamanho em bytes
$bytes = $pdf->size();
```

---

## 5. Endpoints Comuns

### Download direto

```php
Route::post('/export/pdf', function (Request $request, PdfBlockRenderer $renderer) {
    $doc = $request->input('document');
    return $renderer->toPdf($doc)->toResponse('documento.pdf');
});
```

### Preview no browser

```php
Route::post('/export/preview', function (Request $request, PdfBlockRenderer $renderer) {
    $doc = $request->input('document');
    return $renderer->toPdf($doc)->toInlineResponse('preview.pdf');
});
```

### HTML para debug

```php
Route::post('/export/html', function (Request $request, PdfBlockRenderer $renderer) {
    $doc = $request->input('document');
    return $renderer->toHtml($doc);
});
```

### Salvar no storage

```php
Route::post('/export/save', function (Request $request, PdfBlockRenderer $renderer) {
    $doc = $request->input('document');
    $filename = 'exports/' . now()->format('Y-m-d_His') . '.pdf';

    $renderer->toPdf($doc)->save(storage_path($filename));

    return response()->json(['path' => $filename]);
});
```

### Health check

```php
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});
```

---

## 6. Validação do Request

Para validar a estrutura do documento recebido:

```php
Route::post('/export/pdf', function (Request $request, PdfBlockRenderer $renderer) {
    $validated = $request->validate([
        'document' => 'required|array',
        'document.id' => 'required|string',
        'document.version' => 'required|string',
        'document.blocks' => 'required|array',
        'document.pageSettings' => 'required|array',
        'document.pageSettings.paperSize' => 'required|array',
        'document.pageSettings.margins' => 'required|array',
        'document.globalStyles' => 'required|array',
    ]);

    return $renderer->toPdf($validated['document'])->toResponse('documento.pdf');
});
```

---

## 7. Arquitetura de Renderização

```
Editor React                    Laravel
────────────                    ──────
PDFBuilder                      POST /api/export/pdf
    │                               │
    ▼                               ▼
Document (DSL JSON) ──────► PdfBlockRenderer
                                    │
                            toHtml() │ Blade templates
                                    │ (document → stripe → structure → block)
                                    │ Inline styles 1:1 com o editor
                                    ▼
                                HTML string
                                    │
                             toPdf() │ driver selecionado
                                    │   • LocalDriver      → chromium --print-to-pdf
                                    │   • BrowserlessDriver → POST /pdf (libcurl)
                                    ▼
                                PdfResult (PDF nativo)
```

### Paridade Visual

Os templates Blade replicam **1:1** os renderers React:

- Mesmos inline styles via `StyleHelpers.php` (portado do TypeScript)
- Texto convertido de TipTap JSON → HTML via `ueberdosis/tiptap-php`
- Tamanho de papel, margens e orientação lidos via `@page { size }` do
  próprio HTML (`preferCSSPageSize: true` no Chromium)

---

## 8. Blocos Suportados

| Bloco | Descrição |
|---|---|
| `text` | Rich text (TipTap JSON → HTML) |
| `image` | Imagem com alignment, objectFit, bordas |
| `button` | Link estilizado como botão (clicável no PDF) |
| `divider` | Linha horizontal |
| `spacer` | Espaço vertical |
| `banner` | Imagem de fundo com overlay e textos |
| `table` | Tabela com header, zebra striping, bordas |
| `qrcode` | QR Code |
| `chart` | Gráfico de barras (CSS puro) |
| `pagebreak` | Quebra de página forçada |

---

## Próximos Passos

- [Instalação detalhada](installation.md) — Configuração, drivers, troubleshooting
- [Imagem Docker `pdf-block-browserless`](image-build.md) — Build, publicação no GHCR e como adicionar fontes
- [Blade Rendering Guide](blade-rendering-guide.md) — Referência técnica completa
