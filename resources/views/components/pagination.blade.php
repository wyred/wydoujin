@props(['paginator'])

@if ($paginator->hasPages())
    <nav class="flex items-center justify-center" style="gap:var(--space-md); margin-top:var(--space-xl);" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span style="font:var(--type-caption); color:var(--text-muted);">Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Prev</a>
        @endif
        <span style="font:var(--type-caption); color:var(--text-muted);">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Next</a>
        @else
            <span style="font:var(--type-caption); color:var(--text-muted);">Next</span>
        @endif
    </nav>
@endif
