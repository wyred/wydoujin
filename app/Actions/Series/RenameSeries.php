<?php

namespace App\Actions\Series;

use App\Models\Series;
use App\Support\SortKey;
use Illuminate\Support\Facades\DB;

/** Rename a series (and lock its works so detection won't undo it). / シリーズ名変更。 */
final class RenameSeries
{
    public function handle(Series $series, string $name): void
    {
        $name = trim($name);
        abort_if($name === '', 422, 'Name is required.');

        DB::transaction(function () use ($series, $name): void {
            $series->update([
                'name' => $name,
                'sort_name' => SortKey::derive($name),
                'is_auto' => false,
            ]);
            $series->works()->update(['series_locked' => true]);
        });
    }
}
