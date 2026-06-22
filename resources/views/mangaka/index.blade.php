@extends('layouts.app')

@section('content')
    <x-nav active="mangaka" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <x-section-heading>Mangaka</x-section-heading>

        @if ($mangaka->isEmpty())
            <p style="font:var(--type-body); color:var(--text-muted);">No mangaka yet — run <code>wydoujin:scan</code>.</p>
        @else
            <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                @foreach ($mangaka as $artist)
                    <a href="/mangaka/{{ $artist->slug }}" class="no-underline block">
                        <x-cover :path="$artist->rep_cover" :title="$artist->name" />
                        <div class="truncate" style="margin-top:var(--space-xs); font:var(--type-caption-strong); color:var(--text-heading);">{{ $artist->name }}</div>
                        <div style="font:var(--type-fine); color:var(--text-muted);">{{ $artist->works_count }} {{ \Illuminate\Support\Str::plural('work', $artist->works_count) }}</div>
                    </a>
                @endforeach
            </div>

            @if ($mangaka->hasPages())
                <nav class="flex items-center justify-center" style="gap:var(--space-md); margin-top:var(--space-xl);">
                    @if ($mangaka->onFirstPage())
                        <span style="font:var(--type-caption); color:var(--text-muted);">Prev</span>
                    @else
                        <a href="{{ $mangaka->previousPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Prev</a>
                    @endif
                    <span style="font:var(--type-caption); color:var(--text-muted);">Page {{ $mangaka->currentPage() }} of {{ $mangaka->lastPage() }}</span>
                    @if ($mangaka->hasMorePages())
                        <a href="{{ $mangaka->nextPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Next</a>
                    @else
                        <span style="font:var(--type-caption); color:var(--text-muted);">Next</span>
                    @endif
                </nav>
            @endif
        @endif
    </main>
@endsection
