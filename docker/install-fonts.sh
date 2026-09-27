#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# install-fonts.sh
#
# Baixa as 21 fontes curadas do pdf-block para uso pelo Chromium no
# container do Browserless. Prefere variable fonts (.ttf VF) quando
# disponíveis — um único arquivo cobre todos os pesos, reduzindo tamanho.
#
# As famílias aqui DEVEM corresponder exatamente a:
#   - packages/react/src/fontCatalog.ts                (GOOGLE_FONTS)
#   - packages/laravel/config/pdf-block.php            (fonts.local_fonts)
#
# Tamanho total esperado: ~25-35 MB.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

BASE="https://raw.githubusercontent.com/google/fonts/main"
DEST="/usr/local/share/fonts/pdfblock"
mkdir -p "$DEST"

# Helper: download a single font file into DEST. Ignora falha silenciosamente
# para não quebrar o build se o google/fonts rearranjar um arquivo (raríssimo),
# mas loga no stderr para auditoria.
fetch() {
  local path="$1"
  local name
  name=$(basename "$path")
  if ! curl -fsSL --retry 3 --retry-delay 2 -o "$DEST/$name" "$BASE/$path"; then
    echo "  [skip] $path" >&2
  fi
}

echo "→ Baixando 21 fontes curadas para $DEST"

# ─── Variable fonts (preferidas — 1 arquivo cobre todos os pesos) ───────────
# Convenção: [brackets] são literais no nome do arquivo no google/fonts repo.

# Sans
fetch "ofl/inter/Inter%5Bopsz,wght%5D.ttf"
fetch "ofl/inter/Inter-Italic%5Bopsz,wght%5D.ttf"
fetch "ofl/roboto/Roboto%5Bwdth,wght%5D.ttf"
fetch "ofl/roboto/Roboto-Italic%5Bwdth,wght%5D.ttf"
fetch "ofl/opensans/OpenSans%5Bwdth,wght%5D.ttf"
fetch "ofl/opensans/OpenSans-Italic%5Bwdth,wght%5D.ttf"
fetch "ofl/sourcesans3/SourceSans3%5Bwght%5D.ttf"
fetch "ofl/sourcesans3/SourceSans3-Italic%5Bwght%5D.ttf"
fetch "ofl/notosans/NotoSans%5Bwdth,wght%5D.ttf"
fetch "ofl/notosans/NotoSans-Italic%5Bwdth,wght%5D.ttf"
fetch "ofl/worksans/WorkSans%5Bwght%5D.ttf"
fetch "ofl/worksans/WorkSans-Italic%5Bwght%5D.ttf"
fetch "ofl/dmsans/DMSans%5Bopsz,wght%5D.ttf"
fetch "ofl/dmsans/DMSans-Italic%5Bopsz,wght%5D.ttf"
fetch "ofl/montserrat/Montserrat%5Bwght%5D.ttf"
fetch "ofl/montserrat/Montserrat-Italic%5Bwght%5D.ttf"

# Serif
fetch "ofl/lora/Lora%5Bwght%5D.ttf"
fetch "ofl/lora/Lora-Italic%5Bwght%5D.ttf"
fetch "ofl/sourceserif4/SourceSerif4%5Bopsz,wght%5D.ttf"
fetch "ofl/sourceserif4/SourceSerif4-Italic%5Bopsz,wght%5D.ttf"
fetch "ofl/ebgaramond/EBGaramond%5Bwght%5D.ttf"
fetch "ofl/ebgaramond/EBGaramond-Italic%5Bwght%5D.ttf"
fetch "ofl/playfairdisplay/PlayfairDisplay%5Bwght%5D.ttf"
fetch "ofl/playfairdisplay/PlayfairDisplay-Italic%5Bwght%5D.ttf"
fetch "ofl/notoserif/NotoSerif%5Bwdth,wght%5D.ttf"
fetch "ofl/notoserif/NotoSerif-Italic%5Bwdth,wght%5D.ttf"

# Display
fetch "ofl/oswald/Oswald%5Bwght%5D.ttf"

# Mono
fetch "apache/robotomono/RobotoMono%5Bwght%5D.ttf"
fetch "apache/robotomono/RobotoMono-Italic%5Bwght%5D.ttf"

# ─── Static-only fonts (4 pesos cada: Regular, Bold, Italic, BoldItalic) ────

# Spectral (default do editor) — Light incluído para suportar font-weight: 300
for variant in Light LightItalic Regular Bold Italic BoldItalic; do
  fetch "ofl/spectral/Spectral-${variant}.ttf"
done

# Lato
for variant in Regular Bold Italic BoldItalic; do
  fetch "ofl/lato/Lato-${variant}.ttf"
done

# PT Serif
for variant in Regular Bold Italic BoldItalic; do
  fetch "ofl/ptserif/PTSerif-${variant}.ttf"
done

# Libre Baskerville (não tem BoldItalic no repo oficial)
for variant in Regular Bold Italic; do
  fetch "ofl/librebaskerville/LibreBaskerville-${variant}.ttf"
done

# Merriweather
for variant in Regular Bold Italic BoldItalic; do
  fetch "ofl/merriweather/Merriweather-${variant}.ttf"
done

# ─── Display / condensadas (estáticas) ──────────────────────────────────────

# Khand — sans condensada (pesos 300/400/500/600/700; sem itálico). Usada como
# família primária em títulos (fallback declarado: Arial Narrow → Arial). Sem o
# arquivo, o Chromium caía no Liberation Sans (largo), divergindo do editor.
for variant in Light Regular Medium SemiBold Bold; do
  fetch "ofl/khand/Khand-${variant}.ttf"
done

# ─── Atualiza cache do fontconfig ───────────────────────────────────────────
fc-cache -f "$DEST"

echo "✓ Fontes instaladas ($(find "$DEST" -type f | wc -l) arquivos, $(du -sh "$DEST" | cut -f1))"
