<?php

use App\Parsing\MangakaFolder;

test('circle (author) folder yields both tags', function (): void {
    $this->assertSame([['circle', '華容道'], ['author', '松果']], MangakaFolder::tags('華容道 (松果)'));
    $this->assertSame([['circle', 'スタジオBIG-X'], ['author', 'ありのひろし']], MangakaFolder::tags('スタジオBIG-X (ありのひろし)'));
});

test('romaji - japanese folder yields one author tag = whole name', function (): void {
    $this->assertSame([['author', 'Aiueoka - 愛上陸']], MangakaFolder::tags('Aiueoka - 愛上陸'));
});

test('plain folder yields one author tag', function (): void {
    $this->assertSame([['author', 'れむ']], MangakaFolder::tags('れむ'));
});

test('empty folder yields no tags', function (): void {
    $this->assertSame([], MangakaFolder::tags(''));
});
