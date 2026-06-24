@extends('layouts.app')

@php
    // Every dimension-keyed object is built from the one dimension list, so adding a
    // facet type only touches Tag::TYPES. / 次元は単一ソースから生成。
    $dims = \App\Browse\WorkSearch::DIMENSIONS;
    $initial = [
        'q' => $search->q ?? '',
        'groups' => collect($dims)->map(fn ($d) => ['key' => $d, 'label' => ucfirst($d)])->all(),
        'selected' => collect($dims)->mapWithKeys(fn ($d) => [$d => $search->$d])->all(),
        'expanded' => collect($dims)->mapWithKeys(fn ($d) => [$d => false])->all(),
        'within' => collect($dims)->mapWithKeys(fn ($d) => [$d => ''])->all(),
        'facets' => $facets,
        'total' => $total,
        'page' => $works->currentPage(),
        'hasMore' => $hasMore,
    ];
@endphp

@section('content')
    <x-nav active="browse" />

    <div x-data="browse(@js($initial))"
         class="mx-auto w-full flex"
         style="max-width:var(--container-grid); padding:var(--space-lg) var(--space-lg); gap:var(--space-xl); align-items:flex-start;">

        {{-- Mobile "Filters" toggle --}}
        <button type="button" class="lg:hidden" @click="railOpen = !railOpen"
                style="position:fixed; bottom:var(--space-lg); right:var(--space-lg); z-index:30; padding:11px 22px; border:none; border-radius:var(--radius-pill); background:var(--color-primary); color:var(--color-on-primary); font:var(--type-caption-strong); cursor:pointer;"
                x-text="activeCount() ? ('Filters (' + activeCount() + ')') : 'Filters'"></button>

        {{-- Facet rail --}}
        <aside class="shrink-0"
               :class="railOpen ? 'block' : 'hidden lg:block'"
               style="width:240px;">
            <form action="/browse" method="get" @submit.prevent="refresh()" style="margin-bottom:var(--space-lg);">
                <input type="search" name="q" x-model="q" placeholder="Search title…" aria-label="Search title"
                       class="w-full"
                       style="padding:9px 13px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
            </form>

            <template x-for="group in groups" :key="group.key">
                <div style="margin-bottom:var(--space-lg);">
                    <div style="font:var(--type-fine); letter-spacing:0.4px; text-transform:uppercase; color:var(--text-muted); margin-bottom:var(--space-xs);" x-text="group.label"></div>

                    <input x-show="(facets[group.key] || []).length > cap" x-model="within[group.key]"
                           :placeholder="'filter ' + group.label.toLowerCase() + '…'" :aria-label="'filter ' + group.label"
                           class="w-full" style="margin-bottom:var(--space-xs); padding:5px 9px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-fine);">

                    <template x-for="row in visibleRows(group.key)" :key="group.key + '::' + row.value">
                        <label class="flex items-center" style="gap:var(--space-xs); padding:3px 0; cursor:pointer;">
                            <input type="checkbox" :checked="isChecked(group.key, row.value)" @change="toggle(group.key, row.value)"
                                   style="accent-color:var(--color-primary); cursor:pointer;">
                            <span class="truncate" style="flex:1; font:var(--type-caption); color:var(--text-body);" x-text="row.value"></span>
                            <span style="font:var(--type-fine); color:var(--text-muted);" x-text="row.count"></span>
                        </label>
                    </template>

                    <button type="button" x-show="hasMoreRows(group.key)" @click="expanded[group.key] = true"
                            style="margin-top:var(--space-xxs); background:none; border:none; padding:0; cursor:pointer; font:var(--type-fine); color:var(--text-link);">+ show more</button>
                </div>
            </template>
        </aside>

        {{-- Results --}}
        <main class="min-w-0" style="flex:1;">
            <div x-show="error" style="margin-bottom:var(--space-md); padding:var(--space-sm) var(--space-md); border-radius:var(--radius-sm); background:color-mix(in srgb, var(--color-error) 12%, transparent); color:var(--color-error); font:var(--type-caption);">
                Couldn't load results.
                <button type="button" @click="refresh()" style="background:none; border:none; padding:0; cursor:pointer; color:var(--color-error); text-decoration:underline; font:inherit;">Retry</button>
            </div>
            <div class="flex items-center" style="gap:var(--space-md); margin-bottom:var(--space-md);">
                <span style="font:var(--type-caption); color:var(--text-muted);" x-text="total + ' ' + (total === 1 ? 'work' : 'works')"></span>
                <button type="button" x-show="activeCount() > 0" @click="clear()"
                        style="background:none; border:none; padding:0; cursor:pointer; font:var(--type-caption); color:var(--text-link);">Clear filters</button>
            </div>

            <div x-ref="grid" x-show="total > 0" class="grid"
                 style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
                @include('browse._cards', ['works' => $works])
            </div>

            <div x-show="total === 0" style="padding:var(--space-xxl) 0; text-align:center;">
                <p style="font:var(--type-body); color:var(--text-muted);">No works match.</p>
                <button type="button" @click="clear()"
                        style="margin-top:var(--space-sm); background:none; border:none; cursor:pointer; font:var(--type-caption); color:var(--text-link);">Clear filters</button>
            </div>

            <div style="text-align:center; margin-top:var(--space-xl);">
                <button type="button" x-show="hasMore" @click="loadMore()" :disabled="loading"
                        style="padding:9px 22px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption); cursor:pointer;"
                        x-text="loading ? 'Loading…' : 'Load more'"></button>
            </div>
        </main>
    </div>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('browse', (initial) => ({
            q: initial.q ?? '',
            groups: initial.groups ?? [],
            selected: initial.selected ?? {},
            facets: initial.facets ?? {},
            expanded: initial.expanded ?? {},
            within: initial.within ?? {},
            total: initial.total ?? 0,
            page: initial.page ?? 1,
            hasMore: initial.hasMore ?? false,
            loading: false,
            error: false,
            railOpen: false,
            cap: 15,
            _debounce: null,
            _reqId: 0,

            init() {
                this.$watch('q', () => {
                    clearTimeout(this._debounce);
                    this._debounce = setTimeout(() => this.refresh(), 250);
                });
            },

            dims() { return this.groups.map((g) => g.key); },
            isChecked(dim, value) { return this.selected[dim].includes(value); },

            visibleRows(dim) {
                let rows = this.facets[dim] || [];
                const term = (this.within[dim] || '').toLowerCase();
                if (term) rows = rows.filter((r) => r.value.toLowerCase().includes(term));
                if (!this.expanded[dim] && rows.length > this.cap) rows = rows.slice(0, this.cap);
                return rows;
            },
            hasMoreRows(dim) {
                return !this.expanded[dim] && !this.within[dim] && (this.facets[dim]?.length || 0) > this.cap;
            },

            toggle(dim, value) {
                const i = this.selected[dim].indexOf(value);
                if (i === -1) this.selected[dim].push(value); else this.selected[dim].splice(i, 1);
                this.refresh();
            },
            clear() {
                this.q = '';
                this.selected = { circle: [], parody: [], event: [], author: [], flag: [], theme: [] };
                this.refresh();
            },
            activeCount() {
                return this.dims().reduce((n, d) => n + this.selected[d].length, 0) + (this.q ? 1 : 0);
            },

            buildQuery(extra = {}) {
                const p = new URLSearchParams();
                if (this.q) p.set('q', this.q);
                for (const dim of this.dims()) for (const v of this.selected[dim]) p.append(dim + '[]', v);
                for (const [k, v] of Object.entries(extra)) p.set(k, v);
                return p;
            },
            syncUrl() {
                const qs = this.buildQuery().toString();
                history.replaceState(null, '', qs ? ('/browse?' + qs) : '/browse');
            },

            async fetchJson(page) {
                const p = this.buildQuery({ page, format: 'json' });
                const res = await fetch('/browse?' + p.toString(), { headers: { Accept: 'application/json' } });
                return res.json();
            },
            async refresh() {
                this.error = false;
                this.page = 1;
                this.syncUrl();
                const id = ++this._reqId;
                this.loading = true;
                try {
                    const data = await this.fetchJson(1);
                    if (id !== this._reqId) return; // drop stale
                    this.facets = data.facets;
                    this.total = data.total;
                    this.hasMore = data.hasMore;
                    this.$refs.grid.innerHTML = data.html;
                } catch (e) { this.error = true; }
                finally { if (id === this._reqId) this.loading = false; }
            },
            async loadMore() {
                this.error = false;
                const next = this.page + 1;
                const id = ++this._reqId;
                this.loading = true;
                try {
                    const data = await this.fetchJson(next);
                    if (id !== this._reqId) return;
                    this.page = next;
                    this.hasMore = data.hasMore;
                    this.$refs.grid.insertAdjacentHTML('beforeend', data.html);
                } catch (e) { this.error = true; }
                finally { if (id === this._reqId) this.loading = false; }
            },
        }));
    });
    </script>
@endsection
