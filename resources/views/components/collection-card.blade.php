@props(['href', 'path' => null, 'title', 'count'])

{{-- Cover tile for a collection (mangaka / series): cover + title + work count. / コレクションのタイル。 --}}
<a href="{{ $href }}" class="no-underline block">
    <x-cover :path="$path" :title="$title" />
    <div class="truncate" style="margin-top:var(--space-xs); font:var(--type-caption-strong); color:var(--text-heading);">{{ $title }}</div>
    <div style="font:var(--type-fine); color:var(--text-muted);">{{ $count }} {{ \Illuminate\Support\Str::plural('work', $count) }}</div>
</a>
