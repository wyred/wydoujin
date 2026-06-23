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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
