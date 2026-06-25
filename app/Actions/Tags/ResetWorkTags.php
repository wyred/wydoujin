<?php

namespace App\Actions\Tags;

use App\Models\Work;
use App\Tagging\WorkTagSync;

/** Clear the manual lock and re-derive a work's auto tags from its filename. / ロック解除し自動再導出。 */
final class ResetWorkTags
{
    public function __construct(private readonly WorkTagSync $sync) {}

    public function handle(Work $work): void
    {
        $work->update(['tags_locked' => false]);
        $this->sync->sync($work); // re-derive from the filename / ファイル名から再導出
    }
}
