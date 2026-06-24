@extends('layouts.app')

@section('content')
    <x-nav active="mangaka" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <x-page-heading>Mangaka</x-page-heading>

        @if ($mangaka->isEmpty())
            <p style="font:var(--type-body); color:var(--text-muted);">No mangaka yet — run <code>wydoujin:scan</code>.</p>
        @else
            <x-card-grid>
                @foreach ($mangaka as $artist)
                    <x-collection-card href="/mangaka/{{ $artist->slug }}" :path="$artist->rep_cover" :title="$artist->name" :count="$artist->works_count" />
                @endforeach
            </x-card-grid>

            <x-pagination :paginator="$mangaka" />
        @endif
    </main>
@endsection
