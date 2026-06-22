<?php

namespace Tests\Unit\Series;

use App\Series\TitleNormalizer;
use PHPUnit\Framework\TestCase;

class TitleNormalizerTest extends TestCase
{
    private function n(): TitleNormalizer
    {
        return new TitleNormalizer();
    }

    public function test_plain_title_is_unchanged(): void
    {
        $this->assertSame('四畳半物語', $this->n()->stem('四畳半物語'));
        $this->assertSame('Love', $this->n()->stem('Love'));
        $this->assertSame('Lovely', $this->n()->stem('Lovely'));
    }

    public function test_strips_japanese_counter_suffix(): void
    {
        // "二畳目" volume marker → bare stem. / 「二畳目」を除去。
        $this->assertSame('四畳半物語', $this->n()->stem('四畳半物語 二畳目'));
        $this->assertSame('四畳半物語', $this->n()->stem('四畳半物語 三畳目'));
    }

    public function test_strips_part_and_volume_markers(): void
    {
        $this->assertSame('物語', $this->n()->stem('物語 前編'));
        $this->assertSame('物語', $this->n()->stem('物語 後編'));
        $this->assertSame('タイトル', $this->n()->stem('タイトル 上'));
        $this->assertSame('タイトル', $this->n()->stem('タイトル 下'));
        $this->assertSame('作品', $this->n()->stem('作品 第2話'));
        $this->assertSame('作品', $this->n()->stem('作品 その3'));
        $this->assertSame('作品', $this->n()->stem('作品 #4'));
    }

    public function test_strips_trailing_numbers_ascii_and_fullwidth(): void
    {
        $this->assertSame('Title', $this->n()->stem('Title 2'));
        $this->assertSame('Title', $this->n()->stem('Title 02'));
        $this->assertSame('タイトル', $this->n()->stem('タイトル２'));
    }

    public function test_never_returns_empty(): void
    {
        $this->assertSame('123', $this->n()->stem('123'));
        $this->assertSame('上', $this->n()->stem('上'));
        $this->assertSame('前編', $this->n()->stem('  前編  '));
    }
}
