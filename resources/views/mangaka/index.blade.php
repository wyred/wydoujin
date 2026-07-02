@extends('layouts.app')

@section('content')
    <x-nav active="mangaka" />

    <main class="mx-auto w-full" x-data="mangakaIndex(@js(['q' => $q, 'total' => $mangaka->total()]))"
          style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <x-page-heading>Mangaka</x-page-heading>

        {{-- GET form = no-JS fallback; with JS the submit is intercepted and the grid
             live-refreshes instead. / GETフォームはJS無効時のフォールバック。 --}}
        <form action="/mangaka" method="get" @submit.prevent="refresh()" style="margin-bottom:var(--space-lg); max-width:320px;">
            <input type="search" name="q" value="{{ $q }}" x-model="q" placeholder="Search mangaka…" aria-label="Search mangaka"
                   class="w-full"
                   style="padding:9px 13px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
        </form>

        <div x-show="error" style="display:none; margin-bottom:var(--space-md); padding:var(--space-sm) var(--space-md); border-radius:var(--radius-sm); background:color-mix(in srgb, var(--color-error) 12%, transparent); color:var(--color-error); font:var(--type-caption);">
            Couldn't load results.
            <button type="button" @click="refresh()" style="background:none; border:none; padding:0; cursor:pointer; color:var(--color-error); text-decoration:underline; font:inherit;">Retry</button>
        </div>

        @if ($mangaka->isEmpty() && $q === '')
            {{-- Static empty-library message; hidden while a live search is active. --}}
            <p x-show="!q" style="font:var(--type-body); color:var(--text-muted);">No mangaka yet — run <code>wydoujin:scan</code>.</p>
        @endif

        {{-- Server-correct initial visibility (also right for no-JS); Alpine toggles after. --}}
        <div x-show="total === 0 && q"
             style="{{ ($mangaka->total() === 0 && $q !== '') ? '' : 'display:none;' }} padding:var(--space-xxl) 0; text-align:center;">
            <p style="font:var(--type-body); color:var(--text-muted);">No mangaka match.</p>
            <button type="button" @click="clear()"
                    style="margin-top:var(--space-sm); background:none; border:none; cursor:pointer; font:var(--type-caption); color:var(--text-link);">Clear search</button>
        </div>

        <x-card-grid x-ref="grid">
            @include('mangaka._cards')
        </x-card-grid>

        <div x-ref="pagination">
            @include('mangaka._pagination', ['paginator' => $mangaka])
        </div>
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('mangakaIndex', (initial) => ({
            q: initial.q ?? '',
            total: initial.total ?? 0,
            error: false,
            _debounce: null,
            _reqId: 0,

            init() {
                this.$watch('q', () => {
                    clearTimeout(this._debounce);
                    this._debounce = setTimeout(() => this.refresh(), 250);
                });
            },

            clear() { this.q = ''; },   // the watcher triggers refresh()

            syncUrl() {
                history.replaceState(null, '', this.q ? ('/mangaka?q=' + encodeURIComponent(this.q)) : '/mangaka');
            },

            async refresh() {
                clearTimeout(this._debounce);
                this.error = false;
                this.syncUrl();
                const id = ++this._reqId;
                try {
                    const p = new URLSearchParams({ format: 'json' });
                    if (this.q) p.set('q', this.q);
                    const res = await fetch('/mangaka?' + p.toString(), { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    if (id !== this._reqId) return; // drop stale
                    this.total = data.total;
                    this.$refs.grid.innerHTML = data.html;
                    this.$refs.pagination.innerHTML = data.pagination;
                } catch (e) {
                    if (id === this._reqId) this.error = true;
                }
            },
        }));
    });
    </script>
@endsection
