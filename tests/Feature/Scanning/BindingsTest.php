<?php

use App\Archive\ArchiveInspector;
use App\Archive\CoverGenerator;

test('scan config has expected keys', function (): void {
    $this->assertIsArray(config('scan.image_extensions'));
    $this->assertContains('jpg', config('scan.image_extensions'));
    $this->assertNotEmpty(config('scan.library_path'));
    $this->assertNotEmpty(config('scan.data_path'));
    $this->assertIsInt(config('scan.cover.width'));
    $this->assertIsInt(config('scan.cover.quality'));
});

test('archive units resolve from container', function (): void {
    $this->assertInstanceOf(ArchiveInspector::class, app(ArchiveInspector::class));
    $this->assertInstanceOf(CoverGenerator::class, app(CoverGenerator::class));
});
