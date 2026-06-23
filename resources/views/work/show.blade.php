@extends('layouts.app')

@section('content')
    <x-nav />

    <main class="mx-auto w-full" style="max-width:var(--container-text); padding:var(--space-xl) var(--space-lg);">
        <div class="flex" style="gap:var(--space-xl); flex-wrap:wrap;">
            <div style="width:260px; max-width:100%;">
                <x-cover :path="$work->cover_path" :title="$work->title" />
            </div>

            <div style="flex:1; min-width:260px;">
                <h1 style="font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">{{ $work->title }}</h1>

                <div style="margin-top:var(--space-xs); font:var(--type-body); color:var(--text-muted);">
                    <a href="/mangaka/{{ $work->mangaka->slug }}" class="no-underline" style="color:var(--text-link);">{{ $work->mangaka->name }}</a>
                    @if ($work->circle)<span> · {{ $work->circle }}</span>@endif
                    @if ($work->author)<span> · {{ $work->author }}</span>@endif
                </div>

                <div class="flex" style="gap:var(--space-xs); flex-wrap:wrap; margin-top:var(--space-md);">
                    @if ($work->parody)<x-badge>{{ $work->parody }}</x-badge>@endif
                    @if ($work->event)<x-badge>{{ $work->event }}</x-badge>@endif
                    @foreach (($work->flags ?? []) as $flag)<x-badge>{{ $flag }}</x-badge>@endforeach
                </div>

                <p style="margin-top:var(--space-md); font:var(--type-body); color:var(--text-body);">
                    {{ $work->page_count }} pages
                    @if ($work->readingProgress && $work->readingProgress->current_page > 0)
                        · {{ $work->readingProgress->is_completed ? 'Completed' : $work->readingProgress->current_page.'/'.$work->page_count.' read' }}
                    @else
                        · Not started
                    @endif
                </p>

                @if ($work->series)
                    <p style="margin-top:var(--space-xs); font:var(--type-caption);">
                        <a href="/series/{{ $work->series->id }}" class="no-underline" style="color:var(--text-link);">Part of {{ $work->series->name }}</a>
                    </p>
                @endif

                @php
                    $rp = $work->readingProgress;
                    $cta = (! $rp || $rp->current_page < 1) ? 'Read' : ($rp->is_completed ? 'Read again' : 'Continue');
                @endphp
                <div style="margin-top:var(--space-lg);">
                    <x-button href="/work/{{ $work->id }}/read">▶ {{ $cta }}</x-button>
                </div>
            </div>
        </div>
    </main>
@endsection
