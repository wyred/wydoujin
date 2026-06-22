<?php

namespace App\Series;

/** Contract for series detectors. / シリーズ検出のコントラクト。 */
interface SeriesDetectorContract
{
    /** @return array{series_created:int,works_grouped:int} */
    public function detect(): array;
}
