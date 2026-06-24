@extends('layouts.app')

@section('content')
    <x-nav active="home" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        @if (! $hasAnyWork)
            <div class="text-center" style="padding:var(--space-section) 0;">
                <h1 style="font:var(--type-lead); color:var(--text-heading);">No works yet</h1>
                <p style="margin-top:var(--space-sm); font:var(--type-body); color:var(--text-muted);">
                    Run <code>wydoujin:scan</code> to index your library.
                </p>
            </div>
        @else
            <h1 class="sr-only">Library</h1>
            @if ($continueReading->isNotEmpty())
                <section style="margin-bottom:var(--space-xxl);">
                    <x-section-heading>Continue Reading</x-section-heading>
                    <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                        @foreach ($continueReading as $work)
                            <x-work-card :work="$work" />
                        @endforeach
                    </div>
                </section>
            @endif

            <section>
                <x-section-heading>Recently Added</x-section-heading>
                <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                    @foreach ($recentlyAdded as $work)
                        <x-work-card :work="$work" />
                    @endforeach
                </div>
            </section>
        @endif
    </main>
@endsection
