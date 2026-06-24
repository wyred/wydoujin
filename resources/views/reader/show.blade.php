@extends('layouts.app')

@section('content')
@if ($pages < 1)
    <div class="fixed inset-0 flex flex-col items-center justify-center" style="background:var(--color-black); color:var(--color-on-dark); gap:var(--space-md);">
        <p style="font:var(--type-body);">No pages.</p>
        <a href="/work/{{ $work->id }}" class="no-underline" style="color:var(--color-on-dark); font:var(--type-caption);">← Back</a>
    </div>
@else
<div
    x-data="reader({{ $work->id }}, {{ $pages }}, {{ $initialPage }})"
    @keydown.window.arrow-left.prevent="goLeft()"
    @keydown.window.arrow-right.prevent="goRight()"
    @mousemove="showChrome()"
    class="fixed inset-0 overflow-hidden select-none"
    :class="chrome ? '' : 'cursor-none'"
    style="background:var(--color-black);"
>
    {{-- Page image --}}
    <div class="absolute inset-0 flex justify-center" :class="fit === 'width' ? 'overflow-y-auto items-start' : 'items-center'">
        <img :src="pageUrl(page)" :alt="'page ' + page" draggable="false"
             :class="fit === 'width' ? 'w-full h-auto' : 'max-h-screen max-w-full object-contain'">
    </div>

    {{-- Click/tap zones (above image, below chrome) --}}
    <button type="button" class="absolute inset-y-0 left-0 w-1/3 z-10" style="background:none;border:none;" @click="goLeft()" :aria-label="dir === 'rtl' ? 'Next page' : 'Previous page'"></button>
    <button type="button" class="absolute inset-y-0 left-1/3 w-1/3 z-10" style="background:none;border:none;" @click="toggleChrome()" aria-label="Toggle controls"></button>
    <button type="button" class="absolute inset-y-0 right-0 w-1/3 z-10" style="background:none;border:none;" @click="goRight()" :aria-label="dir === 'rtl' ? 'Previous page' : 'Next page'"></button>

    {{-- Top chrome --}}
    <div x-show="chrome" x-transition.opacity class="absolute top-0 inset-x-0 z-20 flex items-center"
         style="gap:var(--space-md); padding:var(--space-sm) var(--space-md); color:var(--color-on-dark); background:var(--reader-scrim);">
        <a href="/work/{{ $work->id }}" class="no-underline shrink-0" style="color:var(--color-on-dark); font:var(--type-body);" aria-label="Back">←</a>
        <span class="truncate" style="flex:1; font:var(--type-caption-strong);">{{ $work->title }}</span>
        <span class="shrink-0" style="font:var(--type-caption);" x-text="page + ' / ' + pages"></span>
        <button type="button" class="shrink-0" @click="settings = !settings" aria-label="Reader settings"
                style="background:none;border:none;cursor:pointer;color:var(--color-on-dark);font-size:18px;line-height:1;">⚙</button>
    </div>

    {{-- Settings popover --}}
    <div x-show="settings" x-transition @click.outside="settings = false" class="absolute z-30"
         style="top:44px; right:var(--space-md); min-width:180px; display:flex; flex-direction:column; gap:var(--space-sm); padding:var(--space-md); background:var(--surface-card); color:var(--text-body); border:1px solid var(--color-hairline); border-radius:var(--radius-md);">
        <div style="font:var(--type-fine); color:var(--text-muted);">Direction</div>
        <div class="flex" style="gap:var(--space-xs);">
            <button type="button" @click="setDir('rtl')" :style="dir === 'rtl' ? activeChip : chip">RTL</button>
            <button type="button" @click="setDir('ltr')" :style="dir === 'ltr' ? activeChip : chip">LTR</button>
        </div>
        <div style="font:var(--type-fine); color:var(--text-muted); margin-top:var(--space-xs);">Fit</div>
        <div class="flex" style="gap:var(--space-xs);">
            <button type="button" @click="setFit('height')" :style="fit === 'height' ? activeChip : chip">Height</button>
            <button type="button" @click="setFit('width')" :style="fit === 'width' ? activeChip : chip">Width</button>
        </div>
    </div>

    {{-- Bottom page slider --}}
    <div x-show="chrome" x-transition.opacity class="absolute bottom-0 inset-x-0 z-20 flex items-center"
         style="gap:var(--space-md); padding:var(--space-sm) var(--space-lg); background:var(--reader-scrim);">
        <input type="range" min="1" :max="pages" x-model.number="page" class="w-full" style="accent-color:var(--color-primary);" :dir="dir === 'rtl' ? 'rtl' : 'ltr'" aria-label="Jump to page">
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('reader', (id, pages, initial) => ({
        id, pages, page: initial,
        dir: localStorage.getItem('wyd-reader-dir') || 'rtl',
        fit: localStorage.getItem('wyd-reader-fit') || 'height',
        chrome: true,
        settings: false,
        _idle: null,
        _save: null,
        chip: 'flex:1;cursor:pointer;padding:6px 10px;border-radius:var(--radius-sm);border:1px solid var(--color-hairline);background:var(--surface-page);color:var(--text-body);font:var(--type-caption);',
        activeChip: 'flex:1;cursor:pointer;padding:6px 10px;border-radius:var(--radius-sm);border:1px solid var(--color-primary);background:var(--color-primary);color:var(--color-on-primary);font:var(--type-caption);',

        init() {
            this.$watch('page', () => { this.preload(); this.saveProgress(); });
            this.preload();
            this.armIdle();
        },
        pageUrl(n) { return '/work/' + this.id + '/page/' + n; },
        next() { if (this.page < this.pages) this.page++; },
        prev() { if (this.page > 1) this.page--; },
        goLeft() { this.dir === 'rtl' ? this.next() : this.prev(); this.showChrome(); },
        goRight() { this.dir === 'rtl' ? this.prev() : this.next(); this.showChrome(); },
        preload() {
            [this.page + 1, this.page + 2].forEach((n) => {
                if (n <= this.pages) { const img = new Image(); img.src = this.pageUrl(n); }
            });
        },
        saveProgress() {
            clearTimeout(this._save);
            this._save = setTimeout(() => {
                window.wyd.postJson('/work/' + this.id + '/progress', { current_page: this.page }).catch(() => {});
            }, 800);
        },
        setDir(d) { this.dir = d; localStorage.setItem('wyd-reader-dir', d); },
        setFit(f) { this.fit = f; localStorage.setItem('wyd-reader-fit', f); },
        showChrome() { this.chrome = true; this.armIdle(); },
        toggleChrome() { this.chrome ? (this.chrome = false) : this.showChrome(); },
        armIdle() { clearTimeout(this._idle); this._idle = setTimeout(() => { this.chrome = false; this.settings = false; }, 2500); },
    }));
});
</script>
@endif
@endsection
