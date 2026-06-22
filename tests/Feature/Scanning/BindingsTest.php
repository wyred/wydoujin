<?php

namespace Tests\Feature\Scanning;

use App\Archive\ArchiveInspector;
use App\Archive\CoverGenerator;
use Tests\TestCase;

class BindingsTest extends TestCase
{
    public function test_scan_config_has_expected_keys(): void
    {
        $this->assertIsArray(config('scan.image_extensions'));
        $this->assertContains('jpg', config('scan.image_extensions'));
        $this->assertNotEmpty(config('scan.library_path'));
        $this->assertNotEmpty(config('scan.data_path'));
        $this->assertIsInt(config('scan.cover.width'));
        $this->assertIsInt(config('scan.cover.quality'));
    }

    public function test_archive_units_resolve_from_container(): void
    {
        $this->assertInstanceOf(ArchiveInspector::class, app(ArchiveInspector::class));
        $this->assertInstanceOf(CoverGenerator::class, app(CoverGenerator::class));
    }
}
