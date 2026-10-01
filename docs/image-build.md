# Imagem Docker `pdf-block-browserless`

Documentação do pipeline de build e publicação da imagem
[`ghcr.io/accusense-tech/pdf-block-browserless`](https://github.com/accusense-tech/pdf-block-laravel/pkgs/container/pdf-block-browserless),
consumida pelo driver `browserless` do `pdf-block/laravel`.

---

## O que a imagem contém

Partindo de `ghcr.io/browserless/chromium:latest`, a imagem adiciona:

- **Noto Color Emoji** (`fonts-noto-color-emoji` reinstalado e configurado
  como família padrão para `emoji` via `/etc/fonts/conf.d/01-noto-color-emoji.conf`).
- **20 famílias corporativas** (Inter, Roboto, Open Sans, Lato, Source Sans 3,
  Noto Sans, Work Sans, DM Sans, Montserrat, Spectral, Merriweather, Lora,
  Source Serif 4, PT Serif, EB Garamond, Libre Baskerville, Playfair Display,
  Noto Serif, Oswald, Roboto Mono) instaladas em `/usr/local/share/fonts/pdfblock`.
- `fontconfig` cache reconstruído (`fc-cache -f`).

Nada mais é alterado — o entrypoint, portas, variáveis de ambiente e API
HTTP do Browserless v2 permanecem idênticos ao upstream.

---

## Arquivos de entrada

Somente estes dois arquivos afetam o conteúdo da imagem:

| Arquivo | Efeito |
|---|---|
| `Dockerfile.browserless` | Receita (FROM, RUN, COPY, fontconfig, etc.) |
| `docker/install-fonts.sh` | Lista e download dos `.ttf` das 20 famílias |

Qualquer outro arquivo no pacote pode mudar sem acionar build.

---

## Pipeline automático

O workflow `.github/workflows/browserless-image.yml` (no monorepo) publica a
imagem em duas situações. Ele é **desacoplado do release do pacote**: a imagem
só rebuilda quando os arquivos dela mudam.

### 1. Mudança nos arquivos da imagem na `main`

Alterou `packages/laravel/Dockerfile.browserless` ou `packages/laravel/docker/**`
e mergeou na `main`? O filtro `paths` aciona o workflow, que publica:

```
ghcr.io/accusense-tech/pdf-block-browserless:latest
ghcr.io/accusense-tech/pdf-block-browserless:<última tag laravel-v*, sem prefixo>
ghcr.io/accusense-tech/pdf-block-browserless:sha-<commit>
```

A tag de versão vem de `git describe --match 'laravel-v*'` — ou seja, é a versão
do pacote **já lançada** no momento do build, não um lançamento novo. Apps que
travam por digest (`@sha256:…`) continuam previsíveis mesmo com `:latest` avançando.

### 2. Manual — `workflow_dispatch`

`gh workflow run browserless-image.yml`. Use quando a base `browserless/chromium`
upstream mudar e você quiser rebuildar sem tocar em arquivo nenhum.

---

## Fluxograma de decisão

```
Alterou só Dockerfile.browserless ou docker/install-fonts.sh?
│
├── Sim → merge na main → workflow dispara via `paths`
│         (publica :latest + :<versão lançada> + :sha-<commit>)
│
└── Nada mudou, mas quero rebuildar (upstream browserless/chromium novo)?
        → gh workflow run browserless-image.yml

Precisa lançar o PACOTE PHP junto? É outro caminho, independente:
        → changeset em @pdf-block/laravel → merge do PR "Version Packages"
          (ver docs/RELEASE.md no monorepo)
```

---

## Build local

Para testar alterações no `Dockerfile.browserless` ou `install-fonts.sh` antes
de publicar:

```bash
# A partir de packages/laravel/
docker build -f Dockerfile.browserless -t pdf-block-browserless:dev .

# Rodar localmente
docker run --rm -p 3001:3000 \
  -e TOKEN="" \
  -e CONCURRENT=1 \
  pdf-block-browserless:dev

# Em outro terminal, testar o endpoint
curl -sS -X POST 'http://localhost:3001/pdf' \
  -H 'Content-Type: application/json' \
  -d '{"html":"<h1>teste</h1>"}' \
  -o /tmp/teste.pdf

file /tmp/teste.pdf
# → /tmp/teste.pdf: PDF document, ...
```

O sandbox (`apps/laravel-sandbox/docker-compose.yml`) pode referenciar a imagem
local trocando `image:` por `build:` — veja o próprio compose para um exemplo
pronto.

---

## Multi-arch

A imagem publicada no GHCR contempla **`linux/amd64`** e **`linux/arm64`**,
cobrindo servidores x86 comuns e Apple Silicon / AWS Graviton.

Se o build no workflow ficar lento demais, você pode reduzir a matriz no YAML
editando a chave `platforms` do passo `Build and push`.

---

## Cache de camadas

O workflow usa cache de camadas via GitHub Actions
(`cache-from: type=gha` / `cache-to: type=gha,mode=max`). Isso faz rebuilds
subsequentes reutilizarem as camadas de `apt-get install` e `install-fonts.sh`
sem re-baixar as 20 famílias de fonte do `google/fonts` toda vez — o build
típico cai de ~4 min para ~30 s quando só a tag muda.

---

## Adicionar uma nova família de fonte

1. Editar [`docker/install-fonts.sh`](../docker/install-fonts.sh) adicionando
   os `fetch "ofl/..."` dos arquivos da nova família.
2. Atualizar [`config/pdf-block.php`](../config/pdf-block.php) →
   `fonts.local_fonts`.
3. Atualizar `packages/react/src/fontCatalog.ts` →
   `GOOGLE_FONTS` (sincroniza com o editor).
4. Merge na `main` → o workflow detecta a mudança em `install-fonts.sh` e
   rebuilda `:latest` automaticamente.

---

## Consumir a imagem

No `docker-compose.yml` do seu app:

```yaml
services:
  browserless:
    image: ghcr.io/accusense-tech/pdf-block-browserless:latest
    # ou trave em uma versão específica:
    # image: ghcr.io/accusense-tech/pdf-block-browserless:v1.2.3
    ports:
      - "3001:3000"
    environment:
      TOKEN: ""
      CONCURRENT: "1"
    mem_limit: 336m
```

A imagem é pública — não é necessário `docker login` em ambientes de
consumo (`pull` anônimo no `ghcr.io` funciona).
