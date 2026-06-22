<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->string('content_hash', 64)->unique();
            $table->foreignId('mangaka_id')->constrained('mangaka')->cascadeOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete();
            $table->string('relative_path', 1024);
            $table->string('filename');
            $table->string('title');
            $table->string('title_raw');
            $table->string('sort_title')->nullable();
            $table->string('event')->nullable();
            $table->string('circle')->nullable();
            $table->string('author')->nullable();
            $table->string('parody')->nullable();
            $table->string('language')->nullable();
            $table->json('flags')->nullable();
            $table->json('entries')->nullable();
            $table->unsignedInteger('page_count')->default(0);
            $table->string('cover_path')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedBigInteger('file_mtime')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_missing')->default(false);
            $table->boolean('series_locked')->default(false);
            $table->timestamps();

            $table->index('mangaka_id');
            $table->index('series_id');
            $table->index('parody');
            $table->index('circle');
            $table->index('event');
            $table->index('is_missing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('works');
    }
};
