<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropIndex(['parody']);
            $table->dropIndex(['circle']);
            $table->dropIndex(['event']);
            $table->dropColumn(['circle', 'parody', 'event', 'author', 'language', 'flags']);
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->string('event')->nullable();
            $table->string('circle')->nullable();
            $table->string('author')->nullable();
            $table->string('parody')->nullable();
            $table->string('language')->nullable();
            $table->json('flags')->nullable();
            $table->index('parody');
            $table->index('circle');
            $table->index('event');
        });
    }
};
