# DSL → Blade/PDF — Guia de Referência para Desenvolvimento

Este documento reúne todas as observações técnicas, armadilhas e padrões aprendidos durante a implementação da tradução DSL → HTML → PDF via Blade. Serve como referência obrigatória ao criar novos blocos, adicionar propriedades ou alterar o comportamento da página.

---

## Sumário

1. [Modelo de Layout da Página](#1-modelo-de-layout-da-página)
2. [Fórmula de Conversão de Unidades](#2-fórmula-de-conversão-de-unidades)
3. [Viewport e Margens no PDF](#3-viewport-e-margens-no-pdf)
4. [Backgrounds: pageBackground vs contentBackground](#4-backgrounds-pagebackground-vs-contentbackground)
5. [Conversão de Conteúdo TipTap (ProseMirror JSON → HTML)](#5-conversão-de-conteúdo-tiptap-prosemirror-json--html)
6. [Whitespace e Espaçamento no Chromium PDF](#6-whitespace-e-espaçamento-no-chromium-pdf)
7. [BlockStyles → CSS Inline](#7-blockstyles--css-inline)
8. [Checklist: Novo Content Block](#8-checklist-novo-content-block)
9. [Checklist: Nova Propriedade em Bloco Existente](#9-checklist-nova-propriedade-em-bloco-existente)
10. [Checklist: Nova Extensão TipTap](#10-checklist-nova-extensão-tiptap)
11. [Armadilhas Conhecidas](#11-armadilhas-conhecidas)

---

## 1. Modelo de Layout da Página

### Hierarquia HTML no Blade (`document.blade.php`)

```
<body>                          ← pageBackground + padding (= margens da DSL)
  <div class="pdfb-content-area">  ← contentBackground, width: 100%
    @foreach ($blocks as $stripe)
      stripe.blade.php          ← blockStyles do stripe + contentMaxWidth + alignment
        structure.blade.php     ← display:flex, colunas com flex-basis, columnGap
          block.blade.php       ← despacho por tipo → blocks/{type}.blade.php
    @endforeach
  </div>
</body>
```

### Hierarquia equivalente no React (CanvasArea)

```
.pdfb-page                     ← pageBackground, width/minHeight = dimensões do papel
  .pdfb-page-content            ← padding = margens, SEM background (transparente)
    .pdfb-content-inner          ← contentBackground, minHeight
      [stripes via SortableContext]
```

> **Atenção:** No React, `.pdfb-page-content` **não tem background** — apenas padding.
> O `contentBackground` fica no `.pdfb-content-inner` para não sobrescrever o `pageBackground`
> na zona das margens. No Blade, o mesmo é feito com `<body>` (page bg + padding) e
> `.pdfb-content-area` (content bg), sem necessidade de clip.

---

## 2. Fórmula de Conversão de Unidades

A fórmula deve ser **idêntica** entre React e Laravel para garantir pixel-perfect rendering:

```
mmToPx(mm) = round(mm × 96 / 25.4, 2)
```

| Sistema | Implementação |
|---------|--------------|
| React (`utils.ts`) | `Math.round(mm * (96/25.4) * 100) / 100` |
| Laravel (`document.blade.php`) | `round($mm * 96 / 25.4, 2)` |

**Constantes úteis:**
- `MM_TO_PX = 96 / 25.4 ≈ 3.7795`
- `MM_TO_PT = 72 / 25.4 ≈ 2.8346`

**Exemplo A4 portrait:**
- Largura: `mmToPx(210) = 793.70px` → `round() = 794px` (viewport)
- Altura: `mmToPx(297) = 1122.52px`
- Margem 20mm: `mmToPx(20) = 75.59px`
- Área de conteúdo: `793.70 - 75.59 - 75.59 = 642.52px`

> **Regra:** Nunca usar `floor()` para a largura de conteúdo. Use `round()` para o viewport
> e a subtração exata para o conteúdo. `floor()` causa diferença de 0.48px que pode gerar
> quebras de linha indesejadas no Chromium.

---

## 3. Viewport e Margens no PDF

### Arquitetura atual (correta)

```
viewport = round(mmToPx(paperWidthMm))     → ex: 794px para A4
Chrome PDF margins = 0 (todos os lados)
body { padding: margens da DSL em px }
paperHeight = scrollHeight / 96 (polegadas)
```

### Por que funciona assim

O Chrome/Puppeteer renderiza o conteúdo no viewport e depois aplica margens do PDF **fora** do viewport. Se usássemos:
- `viewport = contentWidth (642px)` + `Chrome margins = 75px` → as margens do PDF ficam **brancas**, ignorando qualquer CSS `background` do body. Não há como colorir as margens do PDF via CSS.

**Solução:** O viewport cobre o papel inteiro (794px). As margens da DSL viram `padding` no `<body>`. O `background` do body (= `pageBackground`) preenche todo o viewport, incluindo a zona de margens. O Chrome PDF não adiciona margens próprias (todas = 0).

### Cálculo do paperHeight

```php
// scrollHeight do body já inclui o padding (= margens DSL)
$scrollH = $page->evaluate('document.body.scrollHeight')->getReturnValue();
$paperHeightIn = round($scrollH / 96, 6);
```

Não somar margens extras — o scrollHeight já inclui o padding top + bottom do body.

---

## 4. Backgrounds: pageBackground vs contentBackground

| Propriedade DSL | Onde se aplica | React | Blade |
|----------------|---------------|-------|-------|
| `globalStyles.pageBackground` | Papel inteiro (incluindo zona de margens) | `.pdfb-page { background }` | `body { background }` |
| `globalStyles.contentBackground` | Apenas área de conteúdo (dentro das margens) | `.pdfb-content-inner { background }` | `.pdfb-content-area { background }` |

### Armadilha: `background` preenche `padding`

CSS `background` por padrão preenche a **padding-box** (conteúdo + padding). Se colocar `contentBackground` no mesmo elemento que tem `padding` (margens), o background vai cobrir a zona de margens, escondendo o `pageBackground`.

**Solução React:** Separar em dois divs — o div com padding não tem background, o div interno tem o contentBackground.

**Solução Blade:** `<body>` tem o pageBackground + padding. `.pdfb-content-area` (dentro do body, sem padding próprio) tem o contentBackground.

> `background-clip: content-box` **não funciona** de forma confiável porque a shorthand `background` reseta o `background-clip`. Preferir sempre a separação em dois elementos.

---

## 5. Conversão de Conteúdo TipTap (ProseMirror JSON → HTML)

### Pipeline

```
DSL TextBlock.content (ProseMirror JSON)
  → TiptapConverter::toHtml($json)
    → ueberdosis/tiptap-php com extensões registradas
      → HTML string
        → Pós-processamento (empty paragraphs)
          → Inserido no Blade template
```

### Extensões obrigatórias no TiptapConverter

Cada extensão TipTap usada no React **deve** ter um equivalente registrado no `TiptapConverter.php`:

| React (JS) | Laravel (PHP) | O que faz |
|------------|--------------|-----------|
| `StarterKit` | `StarterKit::class` (blockquote desabilitado) | Nodes e marks básicos |
| `TextAlign` | `TextAlign::class` (types: heading, paragraph) | Alinhamento de texto |
| `Color` | `Color::class` | Cor de texto |
| `TextStyle` | `TextStyle::class` | Mark container para atributos de estilo |
| `FontFamily` | `FontFamily::class` | Família de fonte |
| `Underline` | `Underline::class` | Sublinhado |
| `Link` | `Link::class` | Links |
| `Image` | `Image::class` | Imagens inline |
| `FontSize` (custom) | `TextStyleAttributes` (fontSize) | Tamanho de fonte inline |
| `LineHeight` (custom) | `TextStyleAttributes` (lineHeight) | Altura de linha inline |
| `LetterSpacing` (custom) | `TextStyleAttributes` (letterSpacing) | Espaçamento de letras |
| `ColoredBlockquote` | `ColoredBlockquote::class` | Blockquote com `borderColor` |

### Pós-processamento obrigatório

```php
// Parágrafos vazios no TipTap geram <p></p>
// O Chromium colapsa <p></p> para altura 0
// Solução: inserir <br> para manter a altura
$html = str_replace('<p></p>', '<p><br></p>', $html);
```

Sem isso, linhas em branco intencionais no editor desaparecem no PDF.

### Atributos customizados no textStyle

O `TextStyleAttributes.php` registra atributos globais no mark `textStyle`. Cada atributo é mapeado para um `style` inline:

```php
// fontSize: "19px" → style="font-size: 19px"
// lineHeight: "2.5" → style="line-height: 2.5"
// letterSpacing: "1px" → style="letter-spacing: 1px"
```

> **Atenção ao TextAlign:** O `TextAlign` do tiptap-php exige `types` configurado explicitamente.
> Se não especificar `'types' => ['heading', 'paragraph']`, o alinhamento não é aplicado nos nós
> corretos e o HTML fica sem `text-align`.

---

## 6. Whitespace e Espaçamento no Chromium PDF

### O problema

O Chromium (Puppeteer/Browsershot) colapsa espaços ao redor de `<span>` inline quando renderiza PDF. Exemplo:

```html
<p>O <span style="font-size:19px">mercado</span> de</p>
```

Sem `white-space: pre-wrap`, o PDF renderiza: `"Omercadode"` (sem espaços).

### A solução

No CSS do `document.blade.php`, todos os elementos de texto devem ter:

```css
.pdfb-tiptap p,
.pdfb-tiptap h1, .pdfb-tiptap h2, .pdfb-tiptap h3,
.pdfb-tiptap h4, .pdfb-tiptap h5, .pdfb-tiptap h6,
.pdfb-tiptap li {
    white-space: pre-wrap;
}
```

> **Não** usar `pre-wrap` no React/editor — lá o TipTap já cuida do whitespace corretamente.
> Isso é específico do Chromium headless ao gerar PDF.

---

## 7. BlockStyles → CSS Inline

### Mapeamento de `BlockStyles` para CSS

O `StyleHelpers` do Laravel deve produzir CSS **semanticamente idêntico** ao `blockStylesToCSS()` do React:

| Propriedade DSL | CSS gerado |
|----------------|-----------|
| `styles.padding` (EdgeValues) | `padding: {top}px {right}px {bottom}px {left}px` |
| `styles.margin` (EdgeValues) | `margin: {top}px {right}px {bottom}px {left}px` |
| `styles.border.top/right/bottom/left` | `border-{side}: {width}px {style} {color}` |
| `styles.borderRadius` (CornerValues) | `border-radius: {tl}px {tr}px {br}px {bl}px` |
| `styles.shadow` | `box-shadow: {x}px {y}px {blur}px {spread}px {color}` |
| `styles.opacity` | `opacity: {value}` |
| `styles.background` (solid) | `background-color: {color}` |
| `styles.background` (image) | `background-image: url(...)` + size/repeat/position |
| `styles.background` (gradient) | `background: linear-gradient(...)` ou `radial-gradient(...)` |

### Background image: posição

```php
// positionX e positionY aceitam: 'left', 'center', 'right', 'top', 'bottom' ou valor numérico
// background-position: {positionX} {positionY}
```

### Background image: tamanho

```php
// 'cover' → background-size: cover
// 'contain' → background-size: contain
// 'custom' → background-size: auto (!)  ← Não é um valor customizado, é "sem stretch"
```

---

## 8. Checklist: Novo Content Block

Ao criar um novo tipo de content block (ex: `video`, `countdown`, etc.):

### React (pacote `@pdf-block/react`)

- [ ] Definir tipo na DSL (`dsl.ts`) — adicionar na union `ContentBlock`
- [ ] Criar factory em `store.ts` (`createContentBlock` switch case)
- [ ] Criar renderer em `blocks/renderers.tsx`
- [ ] Registrar em `blocks/registry.ts` (`BLOCK_DEFINITIONS`, `CONTENT_BLOCK_TYPES`)
- [ ] Criar painel de propriedades em `components/panel/` se necessário
- [ ] Adicionar no `RightPanel.tsx` (switch de tipo)

### Laravel (pacote `pdf-block/laravel`)

- [ ] Criar template Blade em `resources/views/blocks/{type}.blade.php`
- [ ] O `block.blade.php` já faz dispatch automático por `$block['type']` — não precisa alterar
- [ ] Garantir que o CSS inline produzido é **idêntico** ao React
- [ ] Testar com `POST /api/export/html` para validar o HTML gerado
- [ ] Testar com `POST /api/export/pdf` para validar o PDF final

### Padrão do template Blade

```blade
@php
  use PdfBlock\Laravel\StyleHelpers as S;
  $props = $block['props'] ?? [];
@endphp

<div style="{{ S::blockStyles($block['styles'] ?? []) }}; propriedades-especificas-aqui">
  {{-- conteúdo do bloco --}}
</div>
```

### Regras de ouro

1. **Escape de conteúdo do usuário:** Sempre usar `{{ e($valor) }}` para texto. Nunca `{!! !!}` com input do usuário.
2. **Valores default:** Sempre usar `?? 'valor_padrão'` — a DSL pode ter campos ausentes.
3. **`meta.hideOnExport`:** O `stripe.blade.php` e `structure.blade.php` já filtram blocos com `hideOnExport`. Blocos de conteúdo não precisam checar isso.
4. **CSS idêntico ao React:** Abrir o React, inspecionar o elemento, copiar os estilos computados. O Blade deve gerar o mesmo output.

---

## 9. Checklist: Nova Propriedade em Bloco Existente

Ao adicionar uma nova propriedade a um bloco existente (ex: `borderStyle` no button):

- [ ] Adicionar na interface TypeScript (`dsl.ts`)
- [ ] Atualizar factory (`createContentBlock` em `store.ts`) com valor default
- [ ] Atualizar renderer React (`renderers.tsx`) para usar a nova prop
- [ ] Atualizar painel de propriedades (ex: `ButtonProperties.tsx`)
- [ ] **Atualizar template Blade** (`blocks/{type}.blade.php`) com a mesma lógica
- [ ] Verificar se o `StyleHelpers` precisa de nova função auxiliar
- [ ] Testar: criar bloco no React → exportar JSON → POST no Laravel → comparar HTML

> **Erro comum:** Adicionar a prop no React mas esquecer do Blade.
> O editor fica correto, mas o PDF exportado ignora a propriedade.

---

## 10. Checklist: Nova Extensão TipTap

Ao adicionar uma nova extensão TipTap no React (ex: novo mark `highlight`):

### React

- [ ] Instalar/criar a extensão TipTap
- [ ] Registrar no editor (`TextBlockRenderer` em `renderers.tsx`, array de extensions)
- [ ] Garantir que o JSON gerado pelo TipTap inclui os atributos da extensão

### Laravel

- [ ] Criar classe PHP equivalente se a extensão é customizada
- [ ] Registrar no `TiptapConverter.php` (`addExtension()`)
- [ ] Verificar se os atributos são mapeados corretamente para HTML
- [ ] Testar: digitar conteúdo com a extensão no React → exportar JSON → converter com `TiptapConverter` → verificar HTML

### Armadilhas com extensões TipTap no PHP

1. **`textStyle` marks**: Atributos customizados no `textStyle` (como `fontSize`, `lineHeight`) precisam ser registrados via `TextStyleAttributes` ou extensão equivalente. O `tiptap-php` **não** processa atributos que não foram explicitamente registrados.

2. **`TextAlign` types**: O `TextAlign` no PHP precisa de `'types' => ['heading', 'paragraph']` configurado. Sem isso, nós do tipo `heading` não recebem `text-align`.

3. **Extensões com `renderHTML`**: O PHP usa `renderHTML()` para gerar atributos HTML. Se a extensão React renderiza um atributo customizado (ex: `data-border-color`), o PHP deve produzir o **mesmo** atributo + estilo inline.

4. **`StarterKit` com overrides**: Se você desabilita um nó no `StarterKit` (ex: `blockquote: false`), deve registrar a versão customizada separadamente. O `StarterKit` do PHP aceita a mesma configuração que o JS.

---

## 11. Armadilhas Conhecidas

### 11.1 Parágrafos vazios colapsam no Chromium

**Sintoma:** Linhas em branco no editor desaparecem no PDF.
**Causa:** `<p></p>` tem height 0 no Chromium headless.
**Fix:** Pós-processar: `str_replace('<p></p>', '<p><br></p>', $html)`.

### 11.2 Espaços ao redor de `<span>` somem no PDF

**Sintoma:** Texto como "O **mercado** de" vira "Omercadode" no PDF.
**Causa:** Chromium colapsa whitespace ao redor de spans com `font-size` diferente.
**Fix:** `white-space: pre-wrap` em `p`, `h1`–`h6`, `li` dentro de `.pdfb-tiptap`.

### 11.3 `background-clip: content-box` não funciona com shorthand

**Sintoma:** `contentBackground` cobre as margens mesmo com `background-clip: content-box`.
**Causa:** A propriedade shorthand `background` reseta `background-clip` para `border-box`.
**Fix:** Usar dois elementos separados — o externo com padding (sem bg), o interno com background.

### 11.4 Viewport arredondado causa quebras de linha

**Sintoma:** Texto que cabe em uma linha no editor quebra no PDF.
**Causa:** `floor(contentWidth)` dá 642px em vez de 642.52px → 0.52px a menos → overflow.
**Fix:** Viewport = `round(mmToPx(paperWidth))` (papel inteiro). Content width é resultado da subtração exata (sem floor/round extra).

### 11.5 Fontes de emoji não renderizam no Docker

**Sintoma:** Emojis aparecem como quadrados ou tofu no PDF.
**Causa:** Container Docker não tem fontes de emoji instaladas.
**Fix:** Instalar `fonts-noto-color-emoji` no Dockerfile:
```dockerfile
RUN apt-get update && apt-get install -y fonts-noto-color-emoji
```

### 11.6 Google Fonts não carregam no headless

**Sintoma:** Fonte customizada não aparece no PDF.
**Causa:** O Chromium headless precisa fazer download da fonte antes de renderizar.
**Fix:** O `document.blade.php` inclui `<link>` do Google Fonts. O `PdfBlockRenderer` deve aguardar o carregamento (via `waitUntilNetworkIdle` ou similar).

### 11.7 `hideOnExport` deve ser verificado nos containers

**Sintoma:** Bloco marcado como `hideOnExport` aparece no PDF.
**Causa:** O filtro deve estar no nível do container (stripe/structure), não no bloco individual.
**Fix:** `stripe.blade.php` e `structure.blade.php` já fazem `@if (!($child['meta']['hideOnExport'] ?? false))` antes de renderizar filhos.

### 11.8 Imagens com height fixo vs auto

**Sintoma:** Imagem fica distorcida ou com tamanho errado no PDF.
**Causa:** O React usa `height: auto` por padrão, mas a DSL pode ter `height` fixo.
**Fix:** Respeitar o `height` da DSL se presente. Se ausente, usar `auto`. Sempre manter `max-width: 100%` para não estourar a coluna.

### 11.9 Table: striped rows e header

**Sintoma:** Cores de linhas alternadas diferem entre React e PDF.
**Causa:** A lógica de "qual linha é par/ímpar" depende de `headerRow`.
**Fix:** Striped = `rowIndex % 2 === (headerRow ? 0 : 1)`. Se `headerRow` está ativo, a primeira linha de dados (index 1) é par. Manter a mesma lógica em ambos os lados.

### 11.10 Banner: dupla natureza

**Sintoma:** Confusão entre `BannerBlock` (content block) e `StructureBlock` com `variant: 'banner'`.
**Fix:** São coisas diferentes. O `BannerBlock` é um bloco de conteúdo com título/subtítulo sobre imagem de fundo. O `StructureBlock` com `variant: 'banner'` é um container de colunas com background-image e overlay. Ambos existem como templates Blade separados.

---

## Referência Rápida: Arquivos-Chave

| Arquivo | Responsabilidade |
|---------|-----------------|
| `document.blade.php` | Layout principal (body, CSS global, head) |
| `stripe.blade.php` | Renderiza StripeBlock (full-width row) |
| `structure.blade.php` | Renderiza StructureBlock (container de colunas + banner variant) |
| `block.blade.php` | Dispatch de ContentBlock por tipo |
| `blocks/{type}.blade.php` | Template específico de cada tipo de conteúdo |
| `PdfBlockRenderer.php` | Engine principal: HTML → Chrome → PDF |
| `StyleHelpers.php` | Funções de conversão DSL styles → CSS string |
| `TiptapConverter.php` | ProseMirror JSON → HTML |
| `TextStyleAttributes.php` | Extensão PHP para fontSize/lineHeight/letterSpacing |
| `ColoredBlockquote.php` | Extensão PHP para blockquote com borderColor |
