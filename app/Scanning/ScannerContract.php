<?php

namespace App\Scanning;

use App\Jobs\ProcessZip;

/** Contract for library scanners: plan per-zip tasks, then finalise. / スキャナのコントラクト。 */
interface ScannerContract
{
    /**
     * Resolve mangaka folders and emit one ProcessZip task per zip.
     *
     * @return list<ProcessZip>
     */
    public function planJobs(int $scanId, string $scanStartIso): array;

    /** Missing sweep + orphan prune; returns the count flagged missing. / 欠落数を返す。 */
    public function finalize(string $scanStartIso): int;
}
