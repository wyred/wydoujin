@extends('layouts.app')

@php
    $initial = ['latest' => $latest, 'history' => $history];
@endphp

@section('content')
    <x-nav active="maintenance" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">

        {{-- Scan panel + history (Alpine, live) --}}
        <div x-data="maintenance(@js($initial))">
            <x-page-heading>Library</x-page-heading>

            <div class="flex items-center" style="gap:var(--space-md); margin-bottom:var(--space-xl); flex-wrap:wrap;">
                <x-button type="button" x-on:click="scan()" x-bind:disabled="scanning || busy"
                          x-bind:style="(scanning || busy) ? 'opacity:0.5; pointer-events:none;' : ''">▶ Scan now</x-button>
                <x-button type="button" variant="secondary" x-on:click="openFullConfirm()" x-bind:disabled="scanning || busy"
                          x-bind:style="(scanning || busy) ? 'opacity:0.5; pointer-events:none;' : ''">⟳ Full Rescan</x-button>
                <span style="font:var(--type-caption); color:var(--text-muted);" x-text="panelText()"></span>
                <span x-show="error" x-text="error" style="color:var(--color-error); font:var(--type-caption);"></span>
            </div>

            {{-- Full Rescan confirm dialog (destructive, irreversible) --}}
            <div x-show="confirmFull" x-cloak x-transition.opacity
                 class="fixed inset-0 flex items-center justify-center"
                 style="z-index:50; background:rgba(0,0,0,0.45); padding:var(--space-lg);"
                 x-on:click.self="cancelFull()" x-on:keydown.escape.window="cancelFull()">
                <div role="dialog" aria-modal="true"
                     style="background:var(--surface-card); border:1px solid var(--color-hairline); border-radius:var(--radius-lg); max-width:32rem; width:100%; padding:var(--space-xl);">
                    <h2 style="font:var(--type-lead); color:var(--text-heading); margin-bottom:var(--space-sm);">Full Rescan — this can&#039;t be undone.</h2>
                    <p style="font:var(--type-body); color:var(--text-muted); margin-bottom:var(--space-sm);">This permanently deletes and rebuilds everything derived from your files:</p>
                    <ul style="font:var(--type-body); color:var(--text-muted); margin:0 0 var(--space-md) var(--space-lg); list-style:disc;">
                        <li>All tags and per-work tag edits</li>
                        <li>All tag renames and merges</li>
                        <li>All series groupings (manual and automatic)</li>
                        <li>The entire cover-image cache</li>
                    </ul>
                    <p style="font:var(--type-body-strong); color:var(--text-heading); margin-bottom:var(--space-sm);">Your files and reading progress are kept.</p>
                    <p style="font:var(--type-caption); color:var(--text-muted); margin-bottom:var(--space-lg);">Everything is then re-derived from your filenames using the current scanning rules.</p>
                    <div class="flex items-center justify-end" style="gap:var(--space-md);">
                        <x-button type="button" variant="secondary" x-on:click="cancelFull()">Cancel</x-button>
                        <x-button type="button" x-on:click="confirmFullRescan()"
                                  style="background:var(--color-error); color:var(--color-on-primary); border:1px solid transparent;">Wipe &amp; rebuild</x-button>
                    </div>
                </div>
            </div>

            <x-section-heading>Recent scans</x-section-heading>
            <div style="margin-bottom:var(--space-xxl);">
                <template x-if="history.length === 0">
                    <p style="font:var(--type-body); color:var(--text-muted);">No scans yet — run one.</p>
                </template>
                <template x-for="s in history" x-bind:key="s.id">
                    <div class="flex items-center" style="gap:var(--space-md); padding:var(--space-sm) 0; border-bottom:1px solid var(--color-hairline); flex-wrap:wrap;">
                        <span style="font:var(--type-caption-strong);" x-bind:style="s.status === 'failed' ? 'color:var(--color-error);' : 'color:var(--text-heading);'" x-text="s.status"></span>
                        <span style="font:var(--type-fine); color:var(--text-muted);" x-text="s.triggered_by"></span>
                        <span style="font:var(--type-fine); color:var(--text-muted);" x-text="when(s.started_at)"></span>
                        <span style="font:var(--type-fine); color:var(--text-muted);" x-text="duration(s)"></span>
                        <span style="flex:1; font:var(--type-fine); color:var(--text-muted);" x-text="summary(s)"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Missing works (server-rendered) --}}
        <x-section-heading>Missing works ({{ $missingCount }})</x-section-heading>
        @if ($missing->isEmpty())
            <p style="font:var(--type-body); color:var(--text-muted);">No missing works.</p>
        @else
            <p style="font:var(--type-fine); color:var(--text-muted); margin-bottom:var(--space-md);">These reappear automatically on the next scan when their files return.</p>
            <div>
                @foreach ($missing as $work)
                    <a href="/work/{{ $work->id }}" class="no-underline flex items-center" style="gap:var(--space-md); padding:var(--space-sm) 0; border-bottom:1px solid var(--color-hairline);">
                        <span class="truncate" style="flex:1; font:var(--type-caption-strong); color:var(--text-heading);">{{ $work->title }}</span>
                        <span style="font:var(--type-fine); color:var(--text-muted);">{{ $work->mangaka?->name }}</span>
                    </a>
                @endforeach
            </div>
            <x-pagination :paginator="$missing" />
        @endif
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('maintenance', (initial) => ({
            latest: initial.latest ?? null,
            history: initial.history ?? [],
            busy: false,
            confirmFull: false,
            error: '',
            _poll: null,

            init() {
                if (this.isActive(this.latest)) this.startPolling();
            },
            isActive(s) { return !!s && (s.status === 'queued' || s.status === 'running'); },
            get scanning() { return this.isActive(this.latest); },

            async scan(endpoint = '/scan') {
                if (this.scanning || this.busy) return;
                this.busy = true;
                this.error = '';
                try {
                    const data = await window.wyd.postJson(endpoint);
                    this.latest = data.scan;
                    this.startPolling();
                } catch (e) {
                    this.error = 'Could not start scan — try again.';
                } finally {
                    this.busy = false;
                }
            },
            openFullConfirm() { if (!this.scanning && !this.busy) this.confirmFull = true; },
            cancelFull() { this.confirmFull = false; },
            confirmFullRescan() { this.confirmFull = false; this.scan('/maintenance/full-rescan'); },
            startPolling() {
                clearInterval(this._poll);
                this._poll = setInterval(() => this.tick(), 2000);
            },
            async tick() {
                try {
                    const data = await window.wyd.getJson('/maintenance/status');
                    this.latest = data.scan;
                    if (!this.isActive(this.latest)) {
                        clearInterval(this._poll);
                        if (this.latest && !this.history.some((s) => s.id === this.latest.id)) {
                            this.history.unshift(this.latest);
                        }
                    }
                } catch (e) { /* best-effort; retry next tick */ }
            },

            panelText() {
                const s = this.latest;
                if (!s) return 'Ready.';
                if (s.status === 'queued') return 'Queued…';
                if (s.status === 'running') return 'Running…';
                if (s.status === 'failed') return 'Failed: ' + ((s.stats && s.stats.error) || 'unknown error');
                return 'Completed — ' + this.summary(s);
            },
            summary(s) {
                const st = s.stats || {};
                if (s.status === 'failed') return (st.error || 'failed');
                return ['added ' + (st.added ?? 0), 'updated ' + (st.updated ?? 0),
                        'missing ' + (st.missing ?? 0), 'series +' + (st.series_created ?? 0)].join(' · ');
            },
            duration(s) {
                if (!s.started_at || !s.finished_at) return '';
                return Math.max(0, Math.round((new Date(s.finished_at) - new Date(s.started_at)) / 1000)) + 's';
            },
            when(iso) { return iso ? new Date(iso).toLocaleString() : ''; },
        }));
    });
    </script>
@endsection
