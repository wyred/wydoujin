<?php

namespace App\Series;

/**
 * Reduces a parsed title to its series stem by stripping trailing volume/
 * sequence markers. Pure + idempotent; never returns an empty string.
 * タイトル末尾の巻数・順序マーカーを剥がしシリーズの語幹を得る。冪等。空は返さない。
 */
final class TitleNormalizer
{
    /**
     * Ordered trailing-token strippers, applied repeatedly until stable.
     * 末尾トークンの除去パターン（安定するまで反復適用）。
     *
     * @var string[]
     */
    private const SUFFIX_PATTERNS = [
        // 前編 / 後編 / 中編 / 完結編 / 最終話. / 編・話マーカー。
        '/\s*(前編|後編|中編|前篇|後篇|完結編|最終話)$/u',
        // Counter "N…目" e.g. 二畳目, 三度目, 二回目. / 「N…目」カウンタ。
        '/\s*[0-9０-９一二三四五六七八九十百千]+[^\s0-9０-９]{0,2}目$/u',
        // 第N話 / N話 / N巻 / N部 / N章 (kanji or arabic). / 第N話・N巻など。
        '/\s*第?\s*[0-9０-９一二三四五六七八九十百千]+\s*(話|巻|部|章)$/u',
        // その2 / Vol.2 / Part 2 / vol2. / 巻数表記。
        '/\s*(その|Vol|VOL|vol|Part|PART|part)\.?\s*[0-9０-９]+$/u',
        // #2 / ＃2. / シャープ番号。
        '/\s*[#＃]\s*[0-9０-９]+$/u',
        // Trailing 上 / 中 / 下 volume. / 上中下。
        '/\s*[上中下]$/u',
        // Bare trailing number, ascii or full-width. / 末尾の数字。
        '/\s*[0-9０-９]+$/u',
        // Separators left dangling. / 残った区切り。
        '/[\s\-‐―ー・:：~〜]+$/u',
    ];

    public function stem(string $title): string
    {
        $s = trim($title);
        do {
            $before = $s;
            foreach (self::SUFFIX_PATTERNS as $pattern) {
                $s = trim(preg_replace($pattern, '', $s) ?? $s);
            }
        } while ($s !== $before && $s !== '');

        return $s !== '' ? $s : trim($title);
    }
}
