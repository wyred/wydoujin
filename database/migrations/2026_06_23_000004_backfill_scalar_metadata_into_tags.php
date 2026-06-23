<?php

use App\Tagging\LegacyScalarBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        // Runs after the scalar columns still exist, before they're dropped. / 列削除前に移行。
        (new LegacyScalarBackfill())->run();
    }

    public function down(): void
    {
        // Forward-only: tags are not un-backfilled. / 後方移行なし。
    }
};
