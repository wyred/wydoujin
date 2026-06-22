<?php

namespace App\Scanning;

/** Contract for library scanners. / ライブラリスキャナのコントラクト。 */
interface ScannerContract
{
    /** @return array<string,int> stats (added, updated, moved, missing, failed) */
    public function scan(): array;
}
