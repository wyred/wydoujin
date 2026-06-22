@props(['work'])

@php
    $progress = $work->readingProgress;
    $pages = max(1, (int) $work->page_count);
    $pct = $progress ? min(100, (int) round($progress->current_page / $pages * 100)) : 0;
@endphp

<a href="/work/{{ $work->id }}" class="no-underline block group">
    <x-cover :path="$work->cover_path" :title="$work->title" />

    <div style="margin-top:var(--space-xs);">
        <div class="truncate" style="font:var(--type-caption-strong); color:var(--text-heading);">{{ $work->title }}</div>
        @if ($work->circle)
            <div class="truncate" style="font:var(--type-fine); color:var(--text-muted);">{{ $work->circle }}</div>
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
    </div>
</a>
