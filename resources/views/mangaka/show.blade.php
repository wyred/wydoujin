@extends('layouts.app')

@php
    $manageInitial = ['works' => $manageWorks, 'series' => $manageSeries];
@endphp

@section('content')
    <x-nav active="mangaka" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);"
          x-data="seriesManager(@js($manageInitial))">

        <div class="flex items-center" style="gap:var(--space-md); margin-bottom:var(--space-xl);">
            <h1 style="flex:1; margin:0; font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">{{ $mangaka->name }}</h1>
            @if (! empty($manageWorks))
                <button type="button" @click="toggleManage()"
                        style="padding:7px 16px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption); cursor:pointer;"
                        x-text="managing ? 'Done' : 'Manage'">Manage</button>
            @endif
        </div>

        {{-- Normal grouped view --}}
        <div x-show="!managing">
            @if ($series->isNotEmpty())
                <section style="margin-bottom:var(--space-xxl);">
                    <x-section-heading>Series</x-section-heading>
                    <x-card-grid>
                        @foreach ($series as $s)
                            @php $cover = optional($s->works->first(fn ($w) => $w->cover_path !== null))->cover_path; @endphp
                            <x-collection-card href="/series/{{ $s->id }}" :path="$cover" :title="$s->name" :count="$s->works->count()" />
                        @endforeach
                    </x-card-grid>
                </section>
            @endif

            @if ($standalone->isNotEmpty())
                <section>
                    <x-section-heading>Works</x-section-heading>
                    <x-card-grid>
                        @foreach ($standalone as $work)
                            <x-work-card :work="$work" />
                        @endforeach
                    </x-card-grid>
                </section>
            @endif

            @if ($series->isEmpty() && $standalone->isEmpty())
                <p style="font:var(--type-body); color:var(--text-muted);">No works for this mangaka.</p>
            @endif
        </div>

        {{-- Manage mode: flat checkable list (initial display:none avoids a pre-Alpine flash) --}}
        <div x-show="managing" style="display:none;">
            <template x-for="w in works" :key="w.id">
                <label class="flex items-center" style="gap:var(--space-md); padding:var(--space-sm) 0; border-bottom:1px solid var(--color-hairline); cursor:pointer;">
                    <input type="checkbox" :value="w.id" x-model.number="selected" style="accent-color:var(--color-primary); cursor:pointer;">
                    <span class="truncate" style="flex:1; font:var(--type-caption-strong); color:var(--text-heading);" x-text="w.title"></span>
                    <span style="font:var(--type-fine); color:var(--text-muted);" x-text="w.series ? ('in: ' + w.series) : '—'"></span>
                </label>
            </template>

            <div x-show="selected.length > 0" x-transition
                 style="position:sticky; bottom:0; margin-top:var(--space-lg); padding:var(--space-md); background:var(--surface-card); border:1px solid var(--color-hairline); border-radius:var(--radius-md); display:flex; flex-direction:column; gap:var(--space-sm);">
                <div style="font:var(--type-caption); color:var(--text-muted);" x-text="selected.length + ' selected'"></div>

                <div class="flex items-center" style="gap:var(--space-sm); flex-wrap:wrap;">
                    <input type="text" x-model="newName" placeholder="New series name"
                           style="flex:1; min-width:160px; padding:7px 11px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
                    <button type="button" @click="group()" :disabled="busy || ! newName.trim()"
                            style="padding:7px 16px; border:none; border-radius:var(--radius-pill); background:var(--color-primary); color:var(--color-on-primary); font:var(--type-caption); cursor:pointer;">Create series</button>
                </div>

                <div class="flex items-center" style="gap:var(--space-sm); flex-wrap:wrap;">
                    <select x-model.number="addTarget"
                            style="padding:7px 11px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
                        <option value="">Add to series…</option>
                        <template x-for="s in series" :key="s.id">
                            <option :value="s.id" x-text="s.name"></option>
                        </template>
                    </select>
                    <button type="button" @click="add()" :disabled="busy || ! addTarget"
                            style="padding:7px 16px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption); cursor:pointer;">Add</button>
                    <button type="button" @click="ungroup()" :disabled="busy"
                            style="padding:7px 16px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); background:var(--surface-page); color:var(--text-body); font:var(--type-caption); cursor:pointer;">Remove from series</button>
                </div>

                <div x-show="error" x-text="error" style="color:var(--color-error); font:var(--type-caption);"></div>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('seriesManager', (initial) => ({
            works: initial.works ?? [],
            series: initial.series ?? [],
            managing: false,
            selected: [],
            newName: '',
            addTarget: '',
            busy: false,
            error: '',

            init() {
                this.$watch('selected', () => {
                    if (this.selected.length && ! this.newName) this.newName = this.firstStem();
                });
            },
            toggleManage() { this.managing = ! this.managing; this.selected = []; this.error = ''; },
            firstStem() {
                const w = this.works.find((x) => x.id === this.selected[0]);
                return w ? w.stem : '';
            },

            async post(url, body) {
                this.busy = true;
                this.error = '';
                try {
                    await window.wyd.postJson(url, body);
                    window.location.reload();
                } catch (e) {
                    this.error = 'Action failed — try again.';
                    this.busy = false;
                }
            },
            group() { if (this.newName.trim()) this.post('/series/group', { work_ids: this.selected, name: this.newName.trim() }); },
            add() { if (this.addTarget) this.post('/series/' + this.addTarget + '/add', { work_ids: this.selected }); },
            ungroup() { this.post('/series/ungroup', { work_ids: this.selected }); },
        }));
    });
    </script>
@endsection
