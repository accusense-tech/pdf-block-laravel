@php
  use PdfBlock\Laravel\StyleHelpers as S;

  $style = S::blockStyles($block['styles'] ?? []);
  $maxLevel = (int) ($block['maxLevel'] ?? 6);
  $showPages = ($block['showPageNumbers'] ?? true) ? '1' : '0';
  $title = htmlspecialchars((string) ($block['title'] ?? ''), ENT_QUOTES);
  // Headings do documento, compartilhados pelo PdfBlockRenderer (View::share).
  $headings = array_filter($pdfbTocHeadings ?? [], fn ($h) => (int) ($h['level'] ?? 1) <= $maxLevel);
@endphp
<nav class="pdfb-toc" style="{{ $style }}" data-page-numbers="{{ $showPages }}">
  @if($title)
    <div class="pdfb-toc-title">{!! $title !!}</div>
  @endif
  @if(empty($headings))
    <div class="pdfb-toc-empty">Sem títulos no documento.</div>
  @else
    <ul class="pdfb-toc-list">
      @foreach($headings as $h)
        <li class="pdfb-toc-entry" data-level="{{ (int) ($h['level'] ?? 1) }}">
          <a href="#{{ $h['anchorId'] ?? '' }}"><span class="pdfb-toc-text">{{ ($h['text'] ?? '') !== '' ? $h['text'] : '(sem título)' }}</span></a>
        </li>
      @endforeach
    </ul>
  @endif
</nav>
