<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mangaka_id')->constrained('mangaka')->cascadeOnDelete();
            $table->string('name');
            $table->string('sort_name')->nullable();
            $table->boolean('is_auto')->default(true);
            $table->timestamps();
            $table->index('mangaka_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
