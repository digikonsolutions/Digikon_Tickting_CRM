<?php

namespace App\Support;

/** WCAG 2.x relative luminance and contrast ratio for #RRGGBB colours. */
final class ColorContrast
{
    public const MIN_TEXT_RATIO = 4.5;

    public static function isHex(string $value): bool
    {
        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $value);
    }

    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function ratio(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    public static function passesOnWhite(string $hex): bool
    {
        return self::ratio($hex, '#FFFFFF') >= self::MIN_TEXT_RATIO;
    }
}
