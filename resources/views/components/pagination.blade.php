@props(['paginator', 'window' => 2])

@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $start = max(1, $current - $window);
    $end = min($last, $current + $window);

    // Shared item styles. / 共通スタイル。
    $item = 'display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px;'
        .'padding:0 9px; border-radius:var(--radius-sm); font:var(--type-caption); text-decoration:none;'
        .'border:1px solid var(--color-hairline);';
    $link = $item.'color:var(--text-link); background:var(--surface-page);';
    $currentStyle = $item.'color:var(--color-on-primary); background:var(--color-primary); border-color:transparent;';
    $muted = $item.'color:var(--text-muted); border-color:transparent;';
    $gap = 'display:inline-flex; align-items:center; justify-content:center; min-width:20px; color:var(--text-muted); font:var(--type-caption);';
@endphp

@if ($paginator->hasPages())
    <nav class="flex items-center justify-center" style="gap:var(--space-xs); margin-top:var(--space-xl); flex-wrap:wrap;" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span style="{{ $muted }}" aria-hidden="true">‹ Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="no-underline" style="{{ $link }}">‹ Prev</a>
        @endif

        {{-- Jump to first page + leading gap. / 先頭ページとギャップ。 --}}
        @if ($start > 1)
            <a href="{{ $paginator->url(1) }}" class="no-underline" style="{{ $link }}">1</a>
            @if ($start > 2)
                <span style="{{ $gap }}" aria-hidden="true">…</span>
            @endif
        @endif

        {{-- Windowed page numbers around the current page. / 現在ページ周辺の番号。 --}}
        @for ($p = $start; $p <= $end; $p++)
            @if ($p === $current)
                <span aria-current="page" style="{{ $currentStyle }}">{{ $p }}</span>
            @else
                <a href="{{ $paginator->url($p) }}" class="no-underline" style="{{ $link }}">{{ $p }}</a>
            @endif
        @endfor

        {{-- Trailing gap + jump to last page. / 末尾ページとギャップ。 --}}
        @if ($end < $last)
            @if ($end < $last - 1)
                <span style="{{ $gap }}" aria-hidden="true">…</span>
            @endif
            <a href="{{ $paginator->url($last) }}" class="no-underline" style="{{ $link }}">{{ $last }}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="no-underline" style="{{ $link }}">Next ›</a>
        @else
            <span style="{{ $muted }}" aria-hidden="true">Next ›</span>
        @endif
    </nav>
@endif
