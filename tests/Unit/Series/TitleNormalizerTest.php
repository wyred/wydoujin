<?php

use App\Series\TitleNormalizer;

function nTitleNormalizer(): TitleNormalizer
{
    return new TitleNormalizer();
}

test('plain title is unchanged', function (): void {
    $this->assertSame('四畳半物語', nTitleNormalizer()->stem('四畳半物語'));
    $this->assertSame('Love', nTitleNormalizer()->stem('Love'));
    $this->assertSame('Lovely', nTitleNormalizer()->stem('Lovely'));
});

test('strips japanese counter suffix', function (): void {
    // "二畳目" volume marker → bare stem. / 「二畳目」を除去。
    $this->assertSame('四畳半物語', nTitleNormalizer()->stem('四畳半物語 二畳目'));
    $this->assertSame('四畳半物語', nTitleNormalizer()->stem('四畳半物語 三畳目'));
});

test('strips part and volume markers', function (): void {
    $this->assertSame('物語', nTitleNormalizer()->stem('物語 前編'));
    $this->assertSame('物語', nTitleNormalizer()->stem('物語 後編'));
    $this->assertSame('タイトル', nTitleNormalizer()->stem('タイトル 上'));
    $this->assertSame('タイトル', nTitleNormalizer()->stem('タイトル 下'));
    $this->assertSame('作品', nTitleNormalizer()->stem('作品 第2話'));
    $this->assertSame('作品', nTitleNormalizer()->stem('作品 その3'));
    $this->assertSame('作品', nTitleNormalizer()->stem('作品 #4'));
});

test('strips trailing numbers ascii and fullwidth', function (): void {
    $this->assertSame('Title', nTitleNormalizer()->stem('Title 2'));
    $this->assertSame('Title', nTitleNormalizer()->stem('Title 02'));
    $this->assertSame('タイトル', nTitleNormalizer()->stem('タイトル２'));
});

test('never returns empty', function (): void {
    $this->assertSame('123', nTitleNormalizer()->stem('123'));
    $this->assertSame('上', nTitleNormalizer()->stem('上'));
    $this->assertSame('前編', nTitleNormalizer()->stem('  前編  '));
});
