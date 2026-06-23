@extends('layouts.app')

@section('content')
    <x-nav />

    <main class="mx-auto w-full" style="max-width:var(--container-grid); padding:var(--space-xl) var(--space-lg);">
        <div style="margin-bottom:var(--space-xl);" x-data="seriesRename({{ $series->id }}, @js($series->name))">
            <a href="/mangaka/{{ $series->mangaka->slug }}" class="no-underline" style="font:var(--type-caption); color:var(--text-link);">{{ $series->mangaka->name }}</a>

            <div class="flex items-center" style="gap:var(--space-sm);">
                <h1 x-show="! editing" @click="start()" title="Rename"
                    style="margin:0; font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md); cursor:pointer;"
                    x-text="name">{{ $series->name }}</h1>
                <button type="button" x-show="! editing" @click="start()"
                        style="background:none; border:none; padding:0; cursor:pointer; color:var(--text-link); font:var(--type-caption);">Rename</button>
            </div>

            <div x-show="editing" style="display:none; gap:var(--space-sm); align-items:center;" class="flex">
                <input x-ref="renameInput" type="text" x-model="draft" @keydown.enter.prevent="save()" @keydown.escape="editing = false"
                       style="font:var(--type-body); padding:6px 10px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body);">
                <button type="button" @click="save()" :disabled="busy || ! draft.trim()"
                        style="padding:6px 14px; border:none; border-radius:var(--radius-pill); background:var(--color-primary); color:var(--color-on-primary); font:var(--type-caption); cursor:pointer;">Save</button>
                <button type="button" @click="editing = false"
                        style="padding:6px 14px; border:none; background:none; color:var(--text-muted); font:var(--type-caption); cursor:pointer;">Cancel</button>
                <span x-show="error" x-text="error" style="color:var(--color-error); font:var(--type-caption);"></span>
            </div>
        </div>

        <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--grid-gutter);">
            @foreach ($works as $work)
                <x-work-card :work="$work" />
            @endforeach
        </div>
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('seriesRename', (id, name) => ({
            id,
            name,
            draft: name,
            editing: false,
            busy: false,
            error: '',

            start() { this.draft = this.name; this.editing = true; this.error = ''; this.$nextTick(() => this.$refs.renameInput?.focus()); },
            async save() {
                const v = this.draft.trim();
                if (! v) return;
                this.busy = true;
                this.error = '';
                try {
                    const res = await fetch('/series/' + this.id + '/rename', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        },
                        body: JSON.stringify({ name: v }),
                    });
                    if (! res.ok) throw new Error('http ' + res.status);
                    window.location.reload();
                } catch (e) {
                    this.error = 'Rename failed — try again.';
                    this.busy = false;
                }
            },
        }));
    });
    </script>
@endsection
