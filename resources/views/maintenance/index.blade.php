@extends('layouts.app')

@php
    $initial = ['latest' => $latest, 'history' => $history];
@endphp

@section('content')
    <x-nav active="maintenance" />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">

        {{-- Scan panel + history (Alpine, live) --}}
        <div x-data="maintenance(@js($initial))">
            <x-section-heading>Library</x-section-heading>

            <div class="flex items-center" style="gap:var(--space-md); margin-bottom:var(--space-xl); flex-wrap:wrap;">
                <x-button type="button" x-on:click="scan()" x-bind:disabled="scanning || busy"
                          x-bind:style="(scanning || busy) ? 'opacity:0.5; pointer-events:none;' : ''">▶ Scan now</x-button>
                <span style="font:var(--type-caption); color:var(--text-muted);" x-text="panelText()"></span>
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
            @if ($missing->hasPages())
                <nav class="flex items-center justify-center" style="gap:var(--space-md); margin-top:var(--space-xl);">
                    @if ($missing->onFirstPage())
                        <span style="font:var(--type-caption); color:var(--text-muted);">Prev</span>
                    @else
                        <a href="{{ $missing->previousPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Prev</a>
                    @endif
                    <span style="font:var(--type-caption); color:var(--text-muted);">Page {{ $missing->currentPage() }} of {{ $missing->lastPage() }}</span>
                    @if ($missing->hasMorePages())
                        <a href="{{ $missing->nextPageUrl() }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">Next</a>
                    @else
                        <span style="font:var(--type-caption); color:var(--text-muted);">Next</span>
                    @endif
                </nav>
            @endif
        @endif
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('maintenance', (initial) => ({
            latest: initial.latest ?? null,
            history: initial.history ?? [],
            busy: false,
            _poll: null,

            init() {
                if (this.isActive(this.latest)) this.startPolling();
            },
            isActive(s) { return !!s && (s.status === 'queued' || s.status === 'running'); },
            get scanning() { return this.isActive(this.latest); },

            async scan() {
                if (this.scanning || this.busy) return;
                this.busy = true;
                try {
                    const res = await fetch('/scan', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        },
                    });
                    const data = await res.json();
                    this.latest = data.scan;
                    this.startPolling();
                } catch (e) { /* best-effort */ }
                finally { this.busy = false; }
            },
            startPolling() {
                clearInterval(this._poll);
                this._poll = setInterval(() => this.tick(), 2000);
            },
            async tick() {
                try {
                    const res = await fetch('/maintenance/status', { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    this.latest = data.scan;
                    if (this.latest && !this.isActive(this.latest)) {
                        clearInterval(this._poll);
                        if (!this.history.some((s) => s.id === this.latest.id)) {
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
