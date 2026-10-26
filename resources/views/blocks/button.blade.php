@php
  use PdfBlock\Laravel\StyleHelpers as S;

  // Botão unificado: a pill <a> é o elemento estilizado por `styles` (igual aos
  // demais blocos). Migra docs antigos (campos próprios) para styles.* antes.
  $block  = S::migrateButtonBlock($block);
  $styles = $block['styles'] ?? [];
  $justify = S::justifyCSS($block['alignment'] ?? 'center');

  // Pill: todo o visual vem de styles; a MARGEM externa fica no wrapper (removida
  // da pill via regex, como já se faz para o padding do wrapper de outros casos).
  $pillStyle = preg_replace('/margin:[^;]*;/', '', S::blockStyles($styles));
  $pillStyle .= implode('', [
      // fontSize ausente → herda o font-size do documento (= bloco de texto).
      ! empty($block['fontSize']) ? "font-size:{$block['fontSize']}px;" : '',
      'font-weight:' . ($block['fontWeight'] ?? 600) . ';',
      'color:' . ($block['fontColor'] ?? '#ffffff') . ';',
      ($block['fullWidth'] ?? false) ? 'width:100%;display:block;' : 'display:inline-block;',
      'text-decoration:none;text-align:center;',
      // line-height NUMÉRICO fixo (paridade com o editor): `normal` depende das
      // métricas da fonte e diverge; um múltiplo fixo dá a MESMA altura nos dois.
      'line-height:1.5;',
  ]);

  // Wrapper: só alinha a pill e carrega a margem externa.
  $margin = S::edgeToCSS($styles['margin'] ?? ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]);
@endphp
<div style="margin:{{ $margin }};display:flex;justify-content:{{ $justify }}">
  <a href="{{ S::safeUrl($block['url'] ?? '#') }}" target="{{ $block['target'] ?? '_blank' }}" style="{{ $pillStyle }}">
    {{ $block['text'] ?? 'Clique aqui' }}
  </a>
</div>
