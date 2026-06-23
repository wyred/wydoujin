<?php

use Illuminate\Support\Facades\Schema;

test('tag tables and lock column exist', function (): void {
    $this->assertTrue(Schema::hasTable('tags'));
    $this->assertTrue(Schema::hasTable('work_tag'));
    $this->assertTrue(Schema::hasColumn('works', 'tags_locked'));
});

test('core tables and key columns exist', function (): void {
    $this->assertTrue(Schema::hasTable('mangaka'));
    $this->assertTrue(Schema::hasTable('series'));
    $this->assertTrue(Schema::hasTable('works'));
    $this->assertTrue(Schema::hasTable('reading_progress'));
    $this->assertTrue(Schema::hasTable('scans'));

    $this->assertTrue(Schema::hasColumns('works', [
        'content_hash', 'mangaka_id', 'series_id', 'relative_path',
        'title', 'title_raw', 'sort_title', 'entries', 'page_count',
        'cover_path', 'file_size', 'file_mtime', 'last_seen_at',
        'is_missing', 'series_locked', 'tags_locked',
    ]));
    $this->assertFalse(Schema::hasColumn('works', 'circle'));
    $this->assertFalse(Schema::hasColumn('works', 'flags'));
});
