@props(['path' => null, 'title' => ''])

<div class="w-full aspect-[3/4] overflow-hidden" style="border-radius:var(--radius-md); border:1px solid var(--color-hairline); background:var(--surface-alt);">
    @if ($path)
        <img src="{{ url($path) }}" alt="{{ $title }}" loading="lazy" class="w-full h-full object-cover">
    @else
        <div class="w-full h-full flex items-center justify-center text-center" style="padding:var(--space-md);">
            <span style="font:var(--type-caption); color:var(--text-muted);">{{ $title }}</span>
        </div>
    @endif
</div>
