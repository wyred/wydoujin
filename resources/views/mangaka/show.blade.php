@extends('layouts.app')

@section('content')
    <x-nav active="mangaka" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <h1 style="font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md); margin-bottom:var(--space-xl);">{{ $mangaka->name }}</h1>

        @if ($series->isNotEmpty())
            <section style="margin-bottom:var(--space-xxl);">
                <x-section-heading>Series</x-section-heading>
                <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                    @foreach ($series as $s)
                        @php $cover = optional($s->works->first(fn ($w) => $w->cover_path !== null))->cover_path; @endphp
                        <a href="/series/{{ $s->id }}" class="no-underline block">
                            <x-cover :path="$cover" :title="$s->name" />
                            <div class="truncate" style="margin-top:var(--space-xs); font:var(--type-caption-strong); color:var(--text-heading);">{{ $s->name }}</div>
                            <div style="font:var(--type-fine); color:var(--text-muted);">{{ $s->works->count() }} {{ \Illuminate\Support\Str::plural('work', $s->works->count()) }}</div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($standalone->isNotEmpty())
            <section>
                <x-section-heading>Works</x-section-heading>
                <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                    @foreach ($standalone as $work)
                        <x-work-card :work="$work" />
                    @endforeach
                </div>
            </section>
        @endif

        @if ($series->isEmpty() && $standalone->isEmpty())
            <p style="font:var(--type-body); color:var(--text-muted);">No works for this mangaka.</p>
        @endif
    </main>
@endsection
