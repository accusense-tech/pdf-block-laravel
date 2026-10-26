{{-- Quebra de página explícita.
     A classe é sempre emitida; o comportamento é decidido pelo CSS do
     document.blade.php conforme o pageMode:
       - contínuo: o `* { break-*: avoid }` global neutraliza (no-op);
       - paginado: `.pdfb-block-pagebreak { break-after: page }` força a quebra.
     O elemento é invisível (height:0) em ambos os modos. --}}
<div class="pdfb-block-pagebreak" style="height:0;overflow:hidden"></div>
