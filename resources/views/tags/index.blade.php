@extends('layouts.app')

@php
    $initial = ['groups' => $tagsByType->map(fn ($tags, $type) => [
        'type' => $type,
        'tags' => $tags->map(fn ($t) => ['id' => $t->id, 'value' => $t->value, 'count' => $t->works_count])->values()->all(),
    ])->values()->all()];
@endphp

@section('content')
    <x-nav active="tags" />

    <main class="mx-auto w-full" style="max-width:var(--container-text); padding:var(--space-xl) var(--space-lg);"
          x-data="tagManager(@js($initial))">
        <h1 style="margin:0 0 var(--space-lg); font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">Tags</h1>

        <template x-if="groups.length === 0">
            <p style="font:var(--type-body); color:var(--text-muted);">No tags yet. Run a scan from Maintenance.</p>
        </template>

        <template x-for="group in groups" :key="group.type">
            <section style="margin-bottom:var(--space-xxl);">
                <x-section-heading><span x-text="group.type"></span></x-section-heading>
                <template x-for="tag in group.tags" :key="tag.id">
                    <div class="flex items-center" style="gap:var(--space-sm); padding:var(--space-xs) 0; border-bottom:1px solid var(--color-hairline);">
                        <template x-if="editing !== tag.id">
                            <span class="truncate" style="flex:1; font:var(--type-body); color:var(--text-body);" x-text="tag.value"></span>
                        </template>
                        <template x-if="editing === tag.id">
                            <input type="text" x-model="editValue" @keydown.enter.prevent="rename(tag)" @keydown.escape="editing = null"
                                   style="flex:1; padding:5px 9px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-body);">
                        </template>

                        <span style="font:var(--type-fine); color:var(--text-muted);" x-text="tag.count"></span>

                        <button type="button" x-show="editing !== tag.id" @click="editing = tag.id; editValue = tag.value"
                                style="background:none; border:none; padding:0; cursor:pointer; font:var(--type-fine); color:var(--text-link);">Rename</button>
                        <button type="button" x-show="editing === tag.id" @click="rename(tag)" :disabled="busy"
                                style="background:none; border:none; padding:0; cursor:pointer; font:var(--type-fine); color:var(--text-link);">Save</button>

                        <select @change="mergeTag(tag, $event.target.value); $event.target.value = ''" :disabled="busy" class="wyd-select"
                                style="padding:4px 24px 4px 8px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background-color:var(--surface-page); color:var(--text-body); font:var(--type-fine);">
                            <option value="">Merge into…</option>
                            <template x-for="t in group.tags.filter((o) => o.id !== tag.id)" :key="t.id">
                                <option :value="t.id" x-text="t.value"></option>
                            </template>
                        </select>
                    </div>
                </template>
            </section>
        </template>

        <div x-show="error" x-text="error" style="color:var(--color-error); font:var(--type-caption);"></div>

        <x-pagination :paginator="$tags" />
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('tagManager', (initial) => ({
            groups: initial.groups ?? [],
            editing: null,
            editValue: '',
            busy: false,
            error: '',

            async post(url, body) {
                this.busy = true; this.error = '';
                try {
                    await window.wyd.postJson(url, body);
                    window.location.reload();
                } catch (e) { this.error = 'Action failed — try again.'; this.busy = false; }
            },
            rename(tag) {
                const v = this.editValue.trim();
                if (v && v !== tag.value) this.post('/tags/' + tag.id + '/rename', { value: v });
                else this.editing = null;
            },
            mergeTag(tag, intoId) {
                if (intoId) this.post('/tags/' + tag.id + '/merge', { into_id: Number(intoId) });
            },
        }));
    });
    </script>
@endsection
