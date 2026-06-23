<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_tables_and_lock_column_exist(): void
    {
        $this->assertTrue(Schema::hasTable('tags'));
        $this->assertTrue(Schema::hasTable('work_tag'));
        $this->assertTrue(Schema::hasColumn('works', 'tags_locked'));
    }

    public function test_core_tables_and_key_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('mangaka'));
        $this->assertTrue(Schema::hasTable('series'));
        $this->assertTrue(Schema::hasTable('works'));
        $this->assertTrue(Schema::hasTable('reading_progress'));
        $this->assertTrue(Schema::hasTable('scans'));

        $this->assertTrue(Schema::hasColumns('works', [
            'content_hash', 'mangaka_id', 'series_id', 'relative_path',
            'title', 'title_raw', 'sort_title', 'event', 'circle', 'author',
            'parody', 'language', 'flags', 'entries', 'page_count',
            'cover_path', 'file_size', 'file_mtime', 'last_seen_at',
            'is_missing', 'series_locked',
        ]));
    }
}
