<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: 2026 Martien de Jong
// Source-Id: stm.misc.ascii

declare(strict_types=1);

namespace WpPluginCheck;

/**
 * Plain ASCII for everything sent to Jengo Work.
 *
 * Jengo Work stores a task description with typographic dashes or quotes mangled (mojibake) and
 * answers 200 without an error, so nothing but ASCII is ever sent.
 */
final class Ascii
{
    private const MAP = [
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
        "\u{2026}" => '...', "\u{00A0}" => ' ', "\u{2192}" => '->', "\u{00D7}" => 'x',
    ];

    public static function clean(string $text): string
    {
        $text = strtr($text, self::MAP);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }
        // Whatever is left (or whatever iconv turned into a question mark run) becomes a single '?'.
        $text = (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text);
        return $text;
    }
}
