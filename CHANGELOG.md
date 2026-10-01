# @pdf-block/laravel

## 3.6.0

### Minor Changes

- [#97](https://github.com/accusense-tech/pdf-block/pull/97) [`a496e72`](https://github.com/accusense-tech/pdf-block/commit/a496e7291c6be98df51465215ce69dd5dd310b3f) Thanks [@Jonathas-Augusto](https://github.com/Jonathas-Augusto)! - Fundo da página e do conteúdo em gradiente. `pageSetup.pageBackground` e
  `pageSetup.contentBackground` (e `globalStyles.*` no v2) passam a aceitar, além da
  string de cor, o mesmo `GradientBackground` do fundo de bloco — linear ou radial,
  paradas com token do tema e `ref` para o gradiente nomeado. Documento antigo com
  cor renderiza igual.

  - **React:** o painel da Página usa o campo de preenchimento (Sólido/Gradiente) nos
    dois fundos, com o vínculo "sincronizados" funcionando também com gradiente; o
    sólido continua gravado como string. Canvas contínuo e paginado, minimapa e
    `Ctrl+P` desenham o gradiente. Novos: o tipo `PageBackgroundValue` e os helpers
    `pageBackgroundToCSS` / `pageBackgroundSolidColor`.
  - **Laravel:** `document.blade.php` e o `@page` nomeado por seção emitem o gradiente
    saneado (`StyleHelpers::pageBackgroundCSS`, cada parada pelo `CssSanitizer`); no
    paginado ele recomeça em cada folha (o fundo do conteúdo também). O Chromium só
    pinta cor no `@page`: com o motor de paginação `css` as margens da folha saem na
    cor da 1ª parada; o motor `js` pinta o gradiente inteiro. O e-mail cai na cor da
    1ª parada (`StyleHelpers::pageBackgroundSolid`).

## 3.5.0

### Minor Changes

- [#49](https://github.com/accusense-tech/pdf-block/pull/49) [`0e816df`](https://github.com/accusense-tech/pdf-block/commit/0e816df8f9f90cebb3e5611d0546aa942d465256) Thanks [@Jonathas-Augusto](https://github.com/Jonathas-Augusto)! - O pacote PHP acompanha a auditoria de ago/2026 — e passa a ser publicável de novo.

  Este PR mexeu em `packages/laravel/src/` de verdade e **nenhum changeset o
  declarava**: sem bump, a versão continuaria em 3.4.0, cuja tag o Packagist já
  tem, e o job `packagist` do `release.yml` sairia com "já lançado". Tudo abaixo
  ficaria só no repositório — inclusive a metade PHP da paridade de estilo que o
  `@pdf-block/react` 4.0 assume que existe do outro lado.

  **Fundo de imagem com query string volta a carregar.** `backgroundToCSS` e as
  duas blades que montam o fundo do banner (`v3/group.blade.php`,
  `structure.blade.php`) escapavam a URL com `e()` — mas o atributo já sai por
  `style="{{ }}"`. O escape duplo fazia `?X-Amz-Algorithm=…&X-Amz-Expires=…`
  chegar ao navegador como `&amp;`: assinatura inválida, imagem ausente no PDF.
  Atinge todo fundo servido por presigned S3 ou CDN com query string.

  **`StyleHelpers::cssUrl` (novo).** Dentro de `url(` sem aspas, um `)` fecha a
  função e o resto da URL vira declaração CSS. Os terminadores (`( ) ' " \ < >`)
  passam a ser percent-encodados, com espelho exato em `utils.cssUrl` do TypeScript
  para o canvas e o PDF emitirem o mesmo texto.

  **`StyleHelpers::cssNum` (novo).** Número → string com casas fixas, espelho de
  `cssNum` do TS. PHP e JS convertem float para texto com regras diferentes:
  `33.333333333333336` saía com 14 dígitos de um lado e inteiro do outro, e `1e21`
  virava `1.0E+21`, que não é comprimento CSS. O sintoma seria um gradiente com
  ângulo diferente no PDF e no editor, sem erro em lugar nenhum.

  **Defaults de estilo saem da fonte única.** `FlowRender::defaultStyles` e
  `DocumentMigrator` deixam de carregar cópias à mão da mesma tabela e passam a ler
  `SchemaDefaults::blockStyles()`, gerada de `packages/schema/defaults.json` — a
  mesma origem do TypeScript, com trava de drift (`pnpm parity`).

  **Contrato de entrada do e-mail e `EmailRenderer`** ganham verificação própria
  (`email_input_contract_check.php`, `email_pipeline_parity_check.php`).

  Sem quebra de API: só acréscimo de método público e correção de saída.

## 3.4.0

### Minor Changes

- [#47](https://github.com/accusense-tech/pdf-block/pull/47) [`caead80`](https://github.com/accusense-tech/pdf-block/commit/caead8051e07c9eee7d87f33fbc81121668b4977) Thanks [@Jonathas-Augusto](https://github.com/Jonathas-Augusto)! - Mosaico do banner que ladrilha de verdade, gradiente do banner preservado e três
  refinos no texto rico.

  **Mosaico não repetia.** "Mosaico" gravava `backgroundSize: 'auto'`, que em CSS é o
  tamanho NATURAL da imagem: numa foto comum (1200px+) um único ladrilho já é maior que o
  banner, então aparecia um recorte ampliado — parecia que a imagem tinha sido esticada, e
  nada se repetia. O banner ganha **`backgroundTileSize`** (largura do ladrilho em % da
  largura do banner, default 25), com controle novo no painel visível só no Mosaico. Cobrir
  e Conter ignoram o campo.

  **Gradiente do banner sumia com imagem.** A imagem entrava como `background-image` do
  container, na mesma regra que o gradiente de `styles` já ocupava — duas declarações, a
  última vence, e o gradiente era descartado em silêncio (visível como faixa vazia em volta
  de uma imagem em "Conter"). A imagem passa a ser SEMPRE uma camada própria, que é o
  caminho que o ajuste (recorte/cor/zoom) já exigia.

  **Texto rico:**

  - _Limpar formatação_ não tirava a cor. `unsetAllMarks` só age sobre a seleção, e a cor
    mora em dois lugares: no mark `textStyle` e em `block.fontColor` (aplicada com o cursor
    solto). Sem seleção o botão não tinha o que limpar; com o bloco todo selecionado a cor
    do BLOCO sobrevivia. Agora seleção vazia (ou cobrindo o bloco) limpa marks **e** os
    overrides de tipografia do bloco; seleção parcial segue limpando só o trecho.
  - _Cor da borda da citação_ não aparecia. O botão dependia de `isActive('blockquote')`,
    que devolve false justamente ao SELECIONAR o texto da citação — o gesto natural para
    estilizá-la. A detecção passa a varrer o intervalo da seleção.
  - _Modo grade_ passa a desenhar também os **espaçamentos**: caixa de conteúdo (revela o
    respiro) e caixa de borda (revela a margem), na cor `--pdfb-grid-spacing`, tematizável
    junto com `--pdfb-grid-outline`. Mesmo switch, sem controle novo.

- [#47](https://github.com/accusense-tech/pdf-block/pull/47) [`caead80`](https://github.com/accusense-tech/pdf-block/commit/caead8051e07c9eee7d87f33fbc81121668b4977) Thanks [@Jonathas-Augusto](https://github.com/Jonathas-Augusto)! - Corrige os três bugs do bloco de texto: lista sem marcador, negrito colado na palavra
  anterior e bloco de texto sumindo ao salvar.

  **Lista sem bolinha/número.** O CSS canônico (`styles/tiptapCss.ts` e o espelho em
  `document.blade.php`) nunca declarava `list-style-type` — dependia do UA. O preflight do
  Tailwind, que os apps consumidores carregam, zera `ol, ul, menu { list-style: none }`, e a
  lista aparecia sem marcador no editor e no print do navegador (que reusa o CSS da página).
  Agora o marcador é declarado (`disc`/`decimal`, `circle`/`square` nos níveis aninhados),
  inclusive no editor mini de texto rico e no inliner de e-mail.

  **Negrito colado e espaços sumindo ao salvar.** A causa é o `TrimStrings` +
  `ConvertEmptyStringsToNull` do Laravel — middleware GLOBAL do framework, que apara
  recursivamente toda string do corpo do request, inclusive cada nó `text` do TipTap:
  `"palavra "` vira `"palavra"` (cola no negrito seguinte), `"   ideia"` perde o recuo e uma
  linha só de espaços vira `null` — nó que o ProseMirror recusa, derrubando o bloco inteiro
  ("o texto sumiu"). Duas frentes:

  - `pdf-block/laravel` ganha o middleware `PreserveDocumentWhitespace`, alias
    **`pdf-block.raw`**: nas rotas que recebem documento
    (`->middleware('pdf-block.raw:document,block')`) ele relê essas chaves do corpo cru,
    depois dos globais, e o `validate()` volta a ver o texto intacto. Cirúrgico: os demais
    campos seguem aparados e chave ausente não é criada.
  - `@pdf-block/react` ganha uma rede de segurança para o que já foi gravado danificado
    (`core/richText`): o nó de texto vazio/nulo é removido ao carregar, e o bloco volta a
    renderizar em vez de sumir. Não recupera o espaço perdido — só o middleware evita o dano.

  Documentado em `#/servidor/persistir-documento` e em `docs/CONSUMIDORES.md`.

### Patch Changes

- [#47](https://github.com/accusense-tech/pdf-block/pull/47) [`caead80`](https://github.com/accusense-tech/pdf-block/commit/caead8051e07c9eee7d87f33fbc81121668b4977) Thanks [@Jonathas-Augusto](https://github.com/Jonathas-Augusto)! - Corrige o banner no caminho v2 (documento salvo no modelo antigo): "Conter" saía em
  MOSAICO e o "Ajuste da imagem" não saía de jeito nenhum.

  O `structure.blade.php` emitia `background-image`/`size`/`position` mas **não**
  `background-repeat` — sem a propriedade o CSS cai no default `repeat`, e uma imagem em
  `contain`, que por definição deixa sobra, se repetia para preencher a faixa. Agora espelha
  o v3 (`v3/group.blade.php`) e o editor: ladrilha só quando `backgroundSize` é `auto`
  (Mosaico).

  O mesmo blade e o `StripeRenderer` também ignoravam `adjust` (recorte/enquadramento/cor da
  imagem de fundo), que a projeção v2 carrega desde a versão em que o ajuste entrou: no PDF
  e no canvas v2 a imagem saía sem recorte nenhum. Os dois passam a montar a imagem como
  CAMADA própria, como o v3 já fazia — é o que permite recortar a foto numa curva sem levar
  o texto do banner junto.

## 3.3.1

### Patch Changes

- [#41](https://github.com/accusense-tech/pdf-block/pull/41) [`e100a5d`](https://github.com/accusense-tech/pdf-block/commit/e100a5d095608cd29cf87c764642d91881b5d35d) Thanks [@Jonathas-Augusto](https://github.com/Jonathas-Augusto)! - Corrige o PDF **paginando no modo contínuo**, com uma 2ª folha carregando a cauda
  do documento.

  As imagens com `height:auto` levavam `max-height:100vh` (`blocks/image.blade.php`).
  No print, porém, o viewport **é a folha** — e no contínuo a folha tem a altura do
  documento inteiro, medida por JS. O teto então valia uma coisa na MEDIÇÃO (viewport
  do driver, 800px de altura) e outra no PDF (a folha inteira): a imagem era medida
  truncada em 800px, voltava ao tamanho natural no print e o excedente empurrava uma
  página nova. Aumentar a folha não resolvia — sendo `vh`, a imagem crescia junto.

  Medido no documento real: imagem de 1333px medida como 800px, folha de 1044,6mm
  para 1185,6mm de conteúdo, 2 páginas. Agora sai 1.

  O teto passa a vir de `--pdfb-img-max-h`, definida no `document.blade.php`: altura
  da folha no paginado (equivalente ao que `100vh` valia lá no print) e `none` no
  contínuo, onde limitar não faz sentido. No paginado com motor `css` o PDF sai
  byte a byte idêntico; com o motor `js` o corte das folhas fica mais fiel, porque o
  paginador materializa a partir da tela e agora ela mede o mesmo que o print aplica.

  Trava de regressão em `tests/viewport_units_check.php`: nenhum blade pode
  dimensionar altura em unidade de viewport.
