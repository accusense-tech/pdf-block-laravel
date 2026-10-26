#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# install-fonts.sh
#
# Baixa as 21 fontes curadas do pdf-block para uso pelo Chromium no
# container do Browserless. Prefere variable fonts (.ttf VF) quando
# disponíveis — um único arquivo cobre todos os pesos, reduzindo tamanho.
#
# As famílias aqui DEVEM corresponder exatamente a:
#   - packages/core/src/styles/fonts.ts                 (GOOGLE_FONTS)
#   - packages/laravel/config/pdf-block.php            (fonts.local_fonts)
#
# Tamanho total esperado: ~25-35 MB.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

BASE="https://raw.githubusercontent.com/google/fonts/main"
DEST="/usr/local/share/fonts/pdfblock"
mkdir -p "$DEST"

# Helper: baixa um arquivo de fonte para DEST. Falha NÃO é ignorada: a fonte
# que falta sai trocada pela do sistema no PDF, em silêncio, enquanto o editor
# continua oferecendo a família. Foi assim que Merriweather, PT Serif, Libre
# Baskerville e Roboto Mono sumiram quando o google/fonts mudou os caminhos.
# O build junta as faltas e falha no fim, com a lista.
MISSING=()
fetch() {
  local path="$1"
  local name
  name=$(basename "$path")
  if ! curl -fsSL --retry 3 --retry-delay 2 -o "$DEST/$name" "$BASE/$path"; then
    echo "  [falhou] $path" >&2
    MISSING+=("$path")
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
fetch "ofl/merriweather/Merriweather%5Bopsz,wdth,wght%5D.ttf"
fetch "ofl/merriweather/Merriweather-Italic%5Bopsz,wdth,wght%5D.ttf"
fetch "ofl/librebaskerville/LibreBaskerville%5Bwght%5D.ttf"
fetch "ofl/librebaskerville/LibreBaskerville-Italic%5Bwght%5D.ttf"
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
fetch "ofl/robotomono/RobotoMono%5Bwght%5D.ttf"
fetch "ofl/robotomono/RobotoMono-Italic%5Bwght%5D.ttf"

# ─── Static-only fonts (4 pesos cada: Regular, Bold, Italic, BoldItalic) ────

# Spectral (default do editor) — Light incluído para suportar font-weight: 300
for variant in Light LightItalic Regular Bold Italic BoldItalic; do
  fetch "ofl/spectral/Spectral-${variant}.ttf"
done

# Lato
for variant in Regular Bold Italic BoldItalic; do
  fetch "ofl/lato/Lato-${variant}.ttf"
done

# PT Serif (o repo só tem a versão "Web", com os 4 estilos)
for variant in Regular Bold Italic BoldItalic; do
  fetch "ofl/ptserif/PT_Serif-Web-${variant}.ttf"
done

# ─── Display / condensadas (estáticas) ──────────────────────────────────────

# Khand — sans condensada (pesos 300/400/500/600/700; sem itálico). Usada como
# família primária em títulos (fallback declarado: Arial Narrow → Arial). Sem o
# arquivo, o Chromium caía no Liberation Sans (largo), divergindo do editor.
for variant in Light Regular Medium SemiBold Bold; do
  fetch "ofl/khand/Khand-${variant}.ttf"
done

if [ ${#MISSING[@]} -gt 0 ]; then
  echo "✗ ${#MISSING[@]} fonte(s) não baixaram (caminho mudou no google/fonts?):" >&2
  printf '  %s\n' "${MISSING[@]}" >&2
  exit 1
fi

# ─── Atualiza cache do fontconfig ───────────────────────────────────────────
fc-cache -f "$DEST"

echo "✓ Fontes instaladas ($(find "$DEST" -type f | wc -l) arquivos, $(du -sh "$DEST" | cut -f1))"
