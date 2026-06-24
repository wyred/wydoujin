<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('value');
            $table->string('sort_value')->nullable();
            // Alias/tombstone pointer to the canonical tag (merge/rename). / 別名ポインタ。
            $table->foreignId('merged_into_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index('type');
            $table->index('merged_into_id'); // alias resolution + facet/prune filters (SQLite won't auto-index the FK) / 別名解決とファセット用
            $table->index(['type', 'sort_value']); // /tags + suggest order by type, sort_value / 一覧と候補の並び順
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
