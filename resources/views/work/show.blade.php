@extends('layouts.app')

@section('content')
    <x-nav />

    <main class="mx-auto w-full" style="max-width:var(--container-text); padding:var(--space-xl) var(--space-lg);">
        <div class="flex" style="gap:var(--space-xl); flex-wrap:wrap;">
            <div style="width:260px; max-width:100%;">
                <x-cover :path="$work->cover_path" :title="$work->title" />
            </div>

            <div style="flex:1; min-width:260px;" x-data="workTags(@js(['id' => $work->id, 'locked' => $work->tags_locked, 'tags' => $work->tags->map(fn ($t) => ['id' => $t->id, 'type' => $t->type, 'value' => $t->value])->values()->all(), 'types' => \App\Models\Tag::TYPES]))">
                <h1 style="font:var(--type-display-md); color:var(--text-heading); letter-spacing:var(--tracking-display-md);">{{ $work->title }}</h1>

                @php $byType = $work->tags->groupBy('type'); @endphp
                <div style="margin-top:var(--space-xs); font:var(--type-body); color:var(--text-muted);">
                    <a href="/mangaka/{{ $work->mangaka->slug }}" class="no-underline" style="color:var(--text-link);">{{ $work->mangaka->name }}</a>
                    @foreach (($byType['circle'] ?? []) as $tag)<span> · </span><a href="{{ $tag->browseUrl() }}" class="no-underline" style="color:var(--text-link);">{{ $tag->value }}</a>@endforeach
                    @foreach (($byType['author'] ?? []) as $tag)<span> · </span><a href="{{ $tag->browseUrl() }}" class="no-underline" style="color:var(--text-link);">{{ $tag->value }}</a>@endforeach
                </div>

                <div class="flex" style="gap:var(--space-xs); flex-wrap:wrap; margin-top:var(--space-md);">
                    @foreach (['parody', 'event', 'flag', 'theme'] as $t)
                        @foreach (($byType[$t] ?? []) as $tag)
                            <a href="{{ $tag->browseUrl() }}" class="no-underline"><x-badge>{{ $tag->value }}</x-badge></a>
                        @endforeach
                    @endforeach
                </div>

                <p style="margin-top:var(--space-md); font:var(--type-body); color:var(--text-body);">
                    {{ $work->page_count }} pages
                    @if ($work->readingProgress && $work->readingProgress->current_page > 0)
                        · {{ $work->readingProgress->is_completed ? 'Completed' : $work->readingProgress->current_page.'/'.$work->page_count.' read' }}
                    @else
                        · Not started
                    @endif
                </p>

                @if ($work->series)
                    <p style="margin-top:var(--space-xs); font:var(--type-caption);">
                        <a href="/series/{{ $work->series->id }}" class="no-underline" style="color:var(--text-link);">Part of {{ $work->series->name }}</a>
                    </p>
                @endif

                @php
                    $rp = $work->readingProgress;
                    $cta = (! $rp || $rp->current_page < 1) ? 'Read' : ($rp->is_completed ? 'Read again' : 'Continue');
                @endphp
                <div style="margin-top:var(--space-lg);">
                    <x-button href="/work/{{ $work->id }}/read">▶ {{ $cta }}</x-button>
                </div>

                <div style="margin-top:var(--space-xl); border-top:1px solid var(--color-hairline); padding-top:var(--space-md);">
                    <div class="flex items-center" style="gap:var(--space-sm); margin-bottom:var(--space-sm);">
                        <span style="font:var(--type-fine); letter-spacing:0.4px; text-transform:uppercase; color:var(--text-muted);">Edit tags</span>
                        <span x-show="locked" style="font:var(--type-fine); color:var(--text-muted);">· manual</span>
                        <button type="button" x-show="locked" @click="reset()" :disabled="busy"
                                style="background:none; border:none; padding:0; cursor:pointer; font:var(--type-fine); color:var(--text-link);">Revert to auto</button>
                    </div>

                    <div class="flex" style="gap:var(--space-xs); flex-wrap:wrap; margin-bottom:var(--space-sm);">
                        <template x-for="t in tags" :key="t.id + ':' + t.type">
                            <span class="flex items-center" style="gap:4px; padding:3px 6px 3px 10px; border:1px solid var(--color-hairline); border-radius:var(--radius-pill); font:var(--type-caption); color:var(--text-body);">
                                <span x-text="t.type + ': ' + t.value"></span>
                                <button type="button" @click="detach(t)" :disabled="busy" aria-label="Remove tag"
                                        style="background:none; border:none; cursor:pointer; color:var(--text-muted); font:inherit; line-height:1;">✕</button>
                            </span>
                        </template>
                    </div>

                    <div class="flex items-center" style="gap:var(--space-xs); flex-wrap:wrap; position:relative;">
                        <select x-model="newType" style="padding:6px 9px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
                            <template x-for="ty in types" :key="ty"><option :value="ty" x-text="ty"></option></template>
                        </select>
                        <input type="text" x-model="newValue" @input.debounce.200ms="suggest()" @keydown.enter.prevent="attach()" placeholder="value…"
                               style="flex:1; min-width:140px; padding:6px 9px; border:1px solid var(--color-hairline); border-radius:var(--radius-sm); background:var(--surface-page); color:var(--text-body); font:var(--type-caption);">
                        <button type="button" @click="attach()" :disabled="busy || ! newValue.trim()"
                                style="padding:6px 14px; border:none; border-radius:var(--radius-pill); background:var(--color-primary); color:var(--color-on-primary); font:var(--type-caption); cursor:pointer;">Add</button>

                        <div x-show="suggestions.length" style="position:absolute; top:100%; left:0; right:0; margin-top:4px; background:var(--surface-card); border:1px solid var(--color-hairline); border-radius:var(--radius-sm); z-index:20;">
                            <template x-for="s in suggestions" :key="s">
                                <button type="button" @click="newValue = s; suggestions = []" class="w-full" style="display:block; text-align:left; padding:5px 10px; background:none; border:none; cursor:pointer; font:var(--type-caption); color:var(--text-body);" x-text="s"></button>
                            </template>
                        </div>
                    </div>

                    <div x-show="error" x-text="error" style="margin-top:var(--space-xs); color:var(--color-error); font:var(--type-caption);"></div>
                </div>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('workTags', (initial) => ({
            id: initial.id,
            locked: initial.locked ?? false,
            tags: initial.tags ?? [],
            types: initial.types ?? [],
            newType: (initial.types ?? ['circle'])[0],
            newValue: '',
            suggestions: [],
            busy: false,
            error: '',

            async post(url, body) {
                this.busy = true; this.error = '';
                try {
                    await window.wyd.postJson(url, body);
                    window.location.reload();
                } catch (e) { this.error = 'Action failed — try again.'; this.busy = false; }
            },
            attach() {
                const v = this.newValue.trim();
                if (v) this.post('/work/' + this.id + '/tags/attach', { type: this.newType, value: v });
            },
            detach(t) { this.post('/work/' + this.id + '/tags/detach', { tag_id: t.id }); },
            reset() { this.post('/work/' + this.id + '/tags/reset'); },
            async suggest() {
                const q = this.newValue.trim();
                if (! q) { this.suggestions = []; return; }
                try {
                    this.suggestions = await window.wyd.getJson('/tags/suggest?type=' + encodeURIComponent(this.newType) + '&q=' + encodeURIComponent(q));
                } catch (e) { this.suggestions = []; }
            },
        }));
    });
    </script>
@endsection
