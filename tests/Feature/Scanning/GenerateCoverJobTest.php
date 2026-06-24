<?php

use App\Archive\CoverGenerator;
use App\Jobs\GenerateCover;
use App\Models\Mangaka;
use App\Models\Work;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

/** @param array<string,string> $entries name => bytes */
function writeCoverZip(string $path, array $entries): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    $zip->close();
}

function coverPngBytes(): string
{
    $img = imagecreatetruecolor(20, 30);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

test('generates and stores a cover for a present work', function (): void {
    writeCoverZip($this->libraryPath.'/Circle/Title.zip', ['001.jpg' => coverPngBytes()]);
    $work = Work::factory()->for(Mangaka::factory())->create([
        'relative_path' => 'Circle/Title.zip',
        'entries' => ['001.jpg'],
        'cover_path' => null,
    ]);

    (new GenerateCover($work->id))->handle(app(CoverGenerator::class));

    $work->refresh();
    $this->assertNotNull($work->cover_path);
    $this->assertFileExists($this->dataPath.'/'.$work->cover_path);
});

test('no-ops when the work has vanished', function (): void {
    (new GenerateCover(999999))->handle(app(CoverGenerator::class));

    $this->assertSame(0, Work::count());
});

test('no-ops when the work has no image entries', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create([
        'relative_path' => 'Circle/Empty.zip',
        'entries' => [],
        'cover_path' => null,
    ]);

    (new GenerateCover($work->id))->handle(app(CoverGenerator::class));

    $this->assertNull($work->refresh()->cover_path);
});

test('no-ops when the zip is gone since the scan', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create([
        'relative_path' => 'Circle/Missing.zip', // never written to disk
        'entries' => ['001.jpg'],
        'cover_path' => null,
    ]);

    (new GenerateCover($work->id))->handle(app(CoverGenerator::class));

    $this->assertNull($work->refresh()->cover_path);
});

test('leaves cover null when the image cannot be decoded', function (): void {
    // A .jpg entry holding non-image bytes: CoverGenerator throws, the job swallows it.
    writeCoverZip($this->libraryPath.'/Circle/Garbage.zip', ['001.jpg' => 'not a real image']);
    $work = Work::factory()->for(Mangaka::factory())->create([
        'relative_path' => 'Circle/Garbage.zip',
        'entries' => ['001.jpg'],
        'cover_path' => null,
    ]);

    (new GenerateCover($work->id))->handle(app(CoverGenerator::class));

    $this->assertNull($work->refresh()->cover_path);
    $this->assertFileDoesNotExist($this->dataPath.'/covers/'.$work->content_hash.'.webp');
});
