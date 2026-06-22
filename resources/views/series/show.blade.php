@extends('layouts.app')

@section('content')
    <x-nav />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <div style="margin-bottom:var(--space-xl);">
            <a href="/mangaka/{{ $series->mangaka->slug }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">{{ $series->mangaka->name }}</a>
            <h1 style="font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">{{ $series->name }}</h1>
        </div>

        <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
            @foreach ($works as $work)
                <x-work-card :work="$work" />
            @endforeach
        </div>
    </main>
@endsection
