<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Live per-task counters. Each ProcessZip job atomically increments one of these on its
     * scan row; FinalizeScan folds them (plus the missing-sweep + series stats) into the
     * `stats` JSON. Columns (not JSON) so the increments stay atomic and portable.
     * 各ProcessZipが原子的に加算する集計列。完了時にstats JSONへ畳み込む。
     */
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('moved')->default(0);
            $table->unsignedInteger('failed')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['added', 'updated', 'moved', 'failed']);
        });
    }
};
