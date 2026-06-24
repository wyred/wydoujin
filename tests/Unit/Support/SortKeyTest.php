<?php

use App\Support\SortKey;

test('derive strips leading symbols and brackets', function (): void {
    $this->assertSame('Title', SortKey::derive('★Title'));
    $this->assertSame('Title', SortKey::derive('  「Title'));
    $this->assertSame('四畳半物語', SortKey::derive('四畳半物語'));
    $this->assertSame('abc', SortKey::derive('...abc'));
});

test('derive falls back to trimmed input when all stripped', function (): void {
    $this->assertSame('!!!', SortKey::derive('  !!!  '));
});
