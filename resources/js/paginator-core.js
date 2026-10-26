/**
 * Núcleo COMPARTILHADO do paginador por recorte (clip-window) — FONTE ÚNICA.
 *
 * Consumidores:
 *  - Servidor (engine 'js'): injetado no document.blade.php via
 *    PaginatorScript::core() (arquivo copiado por `pnpm gen` para
 *    packages/laravel/resources/js/paginator-core.js).
 *  - Testes: packages/react/src/pagination/serverPaginatorCore.test.ts
 *    (importa este arquivo via require — ramo CommonJS do UMD).
 *
 * REGRAS: JS puro ES5-compatível (roda cru no Chromium headless dentro do
 * blade), sem imports, sem sintaxe de template literal com `${` (o Blade não
 * interfere, mas mantemos previsível), sem `{{` (delimitador do Blade).
 * O algoritmo espelha o comportamento do @page CSS do Chrome:
 *  - avoidSpans: blocos indivisíveis (break-inside: avoid) — um corte dentro
 *    deles sobe para o topo do bloco;
 *  - forcedCuts: quebras forçadas (break-before/after) vencem o corte por altura;
 *  - computeCuts: janela de altura útil, com guarda para bloco maior que a página.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.PdfbPaginatorCore = factory();
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  // Ys (relativas ao conteúdo) de blocos INDIVISÍVEIS (texto/imagem/tabela e
  // containers marcados como átomos): um corte que cai DENTRO deles sobe para
  // o topo do bloco (empurra para a próxima página).
  function avoidSpans(content) {
    var cTop = content.getBoundingClientRect().top;
    var els = content.querySelectorAll('.pdfb-tiptap, .pdfb-block-image, table, [data-atomic], [data-multi-column]');
    var spans = [];
    for (var k = 0; k < els.length; k++) {
      var r = els[k].getBoundingClientRect();
      spans.push({ top: r.top - cTop, bottom: r.bottom - cTop });
    }
    return spans;
  }

  // Ys (relativas ao conteúdo) das quebras FORÇADAS: topo de [data-break-before]
  // e base de [data-break-after]/.pdfb-block-pagebreak.
  function forcedCuts(content, H) {
    var cTop = content.getBoundingClientRect().top;
    var ys = [];
    var before = content.querySelectorAll('[data-break-before="true"]');
    for (var i = 0; i < before.length; i++) ys.push(before[i].getBoundingClientRect().top - cTop);
    var after = content.querySelectorAll('[data-break-after="true"], .pdfb-block-pagebreak');
    for (var j = 0; j < after.length; j++) ys.push(after[j].getBoundingClientRect().bottom - cTop);
    ys = ys.filter(function (y) { return y > 1 && y < H - 1; }).sort(function (a, b) { return a - b; });
    return ys;
  }

  // Cortes de página: [0, …, H]. `usableH` = altura útil por página (px).
  function computeCuts(H, spans, forced, usableH) {
    function snap(y) {
      for (var k = 0; k < spans.length; k++) {
        if (y > spans[k].top + 1 && y < spans[k].bottom - 1) return spans[k].top;
      }
      return y;
    }
    var cuts = [0], guard = 0;
    while (cuts[cuts.length - 1] < H - 1 && guard++ < 2000) {
      var start = cuts[cuts.length - 1];
      var raw = start + usableH;
      // Quebra forçada dentro da janela desta página vence o corte por altura.
      var f = null;
      for (var q = 0; q < forced.length; q++) {
        if (forced[q] > start + 1 && forced[q] < raw - 0.5) { f = forced[q]; break; }
      }
      if (f !== null) { cuts.push(f); continue; }
      if (raw >= H) { cuts.push(H); break; }
      var y = snap(raw);
      if (y <= start + 1) y = raw; // bloco maior que a página → aceita o corte (overflow, igual ao Chrome)
      cuts.push(y);
    }
    if (cuts.length < 2) cuts.push(H);
    return cuts;
  }

  return { avoidSpans: avoidSpans, forcedCuts: forcedCuts, computeCuts: computeCuts };
}));
