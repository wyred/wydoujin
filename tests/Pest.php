<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot the app and reset the DB per test. / Featureはアプリ起動＋毎テストDBリセット。
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests use the base TestCase, no DB reset. / Unitは基底TestCase（DBリセットなし）。
pest()->extend(TestCase::class)->in('Unit');

// Browser tests (Pest 4 + Playwright) drive the real app; reset DB per test. Run explicitly
// (`pest tests/Browser`), kept out of the default suite. / ブラウザテスト（明示実行）。
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');
