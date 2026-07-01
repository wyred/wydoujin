@props(['work'])

@php
    $progress = $work->readingProgress;
    $pages = max(1, (int) $work->page_count);
    $pct = $progress ? min(100, (int) round($progress->current_page / $pages * 100)) : 0;
@endphp

<div class="group relative">
    {{-- Cover with the play-button shortcut layered on top. --}}
    <div class="relative">
        <x-cover :path="$work->cover_path" :title="$work->title" />

        {{-- Detail click layer: covers the whole cover so clicking anywhere here
             (except the play circle above) opens the detail page. Hidden from AT /
             keyboard — the title link below is the accessible detail link. --}}
        <a href="/work/{{ $work->id }}" aria-hidden="true" tabindex="-1"
           class="absolute inset-0 z-10" style="border-radius:var(--radius-md);"></a>

        {{-- Scrim: decorative dim on hover; never intercepts clicks. --}}
        <div aria-hidden="true"
             class="wyd-card-scrim absolute inset-0 z-20 pointer-events-none"
             style="border-radius:var(--radius-md); background:color-mix(in srgb, var(--color-ink) 35%, transparent);"></div>

        {{-- Play button. The centering layer passes clicks through
             (pointer-events:none) EXCEPT on the circle itself, so only the circle
             navigates to the reader; everywhere else falls through to the detail
             layer beneath. --}}
        <div class="absolute inset-0 z-30 flex items-center justify-center pointer-events-none">
            <a href="/work/{{ $work->id }}/read" aria-label="Read {{ $work->title }}"
               class="wyd-card-play pointer-events-auto flex items-center justify-center"
               style="width:56px; height:56px; border-radius:var(--radius-pill);
                      background:color-mix(in srgb, var(--color-ink) 55%, transparent);
                      border:1px solid var(--color-hairline);">
                {{-- Play triangle (CSS shape), nudged right for optical centering. --}}
                <span aria-hidden="true" style="display:block; width:0; height:0; margin-left:4px;
                      border-top:9px solid transparent; border-bottom:9px solid transparent;
                      border-left:15px solid var(--color-on-primary);"></span>
            </a>
        </div>
    </div>

    {{-- Title / circle / progress — the keyboard-focusable link to the detail page. --}}
    <a href="/work/{{ $work->id }}" class="no-underline block" style="margin-top:var(--space-xs);">
        <div class="truncate" style="font:var(--type-caption-strong); color:var(--text-heading);">{{ $work->title }}</div>
        @php $circle = $work->tags->firstWhere('type', 'circle'); @endphp
        @if ($circle)
            <div class="truncate" style="font:var(--type-fine); color:var(--text-muted);">{{ $circle->value }}</div>
        @endif

        @if ($progress && $progress->current_page > 0)
            @if ($progress->is_completed)
                <div style="margin-top:4px; font:var(--type-fine); color:var(--text-link);">Completed</div>
            @else
                <div style="margin-top:6px; height:3px; border-radius:var(--radius-pill); background:var(--color-hairline);">
                    <div style="height:100%; width:{{ $pct }}%; border-radius:var(--radius-pill); background:var(--color-primary);"></div>
                </div>
                <div style="margin-top:4px; font:var(--type-fine); color:var(--text-muted);">{{ $progress->current_page }}/{{ $work->page_count }}</div>
            @endif
        @endif
    </a>
</div>
