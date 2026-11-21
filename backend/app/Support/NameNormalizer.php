<?php

namespace App\Support;

/**
 * Tidies person and organisation names without damaging names that are already written deliberately.
 *
 * Always: trim and collapse any run of whitespace (including non-breaking spaces) to one space.
 * Title-casing happens only when the input is clearly broken: it is entirely UPPER or entirely lower case, or a
 * word has broken mid-word capitals ("mAHNOOR", "jOHN": a lower-case start with two or more capitals, or a
 * lower-case letter followed by two or more capitals). Names such as "McDonald", "DeShawn" or "iConnect" are
 * left exactly as typed.
 */
class NameNormalizer
{
    /** Left lower case inside a person's name (not as the first word). */
    private const PERSON_PARTICLES = ['van', 'von', 'de', 'del', 'der', 'di', 'da', 'bin', 'binti', 'ibn', 'al', 'el'];

    /** Left lower case inside an organisation's name (not as the first word). */
    private const ORGANISATION_PARTICLES = ['and', 'of', 'the', 'for', 'de'];

    /** A short ALL-CAPS word in an organisation name ("GHL", "SEO", "LLC") is an acronym, not shouting. */
    private const MAX_ACRONYM_LENGTH = 3;

    public static function person(string $name): string
    {
        $name = self::collapse($name);

        return self::needsFixing($name) ? self::titleCase($name, self::PERSON_PARTICLES, false) : $name;
    }

    public static function organization(string $name): string
    {
        $name = self::collapse($name);

        return self::needsFixing($name) ? self::titleCase($name, self::ORGANISATION_PARTICLES, true) : $name;
    }

    public static function collapse(string $name): string
    {
        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $name));
    }

    public static function needsFixing(string $name): bool
    {
        if (! preg_match('/\p{L}/u', $name)) {
            return false;
        }
        $hasLower = (bool) preg_match('/\p{Ll}/u', $name);
        $hasUpper = (bool) preg_match('/\p{Lu}/u', $name);
        if (! $hasLower || ! $hasUpper) {
            return true;
        }

        foreach (explode(' ', $name) as $word) {
            if (preg_match('/\p{Ll}\p{Lu}{2,}/u', $word)) {
                return true;
            }
            if (preg_match('/^\p{Ll}/u', $word) && preg_match_all('/\p{Lu}/u', $word) >= 2) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $particles */
    private static function titleCase(string $name, array $particles, bool $keepAcronyms): string
    {
        $allUpper = ! preg_match('/\p{Ll}/u', $name);
        $words = explode(' ', $name);

        foreach ($words as $index => $word) {
            $isAcronym = $keepAcronyms && $allUpper && preg_match('/^\p{Lu}{2,'.self::MAX_ACRONYM_LENGTH.'}$/u', $word)
                && ! in_array(mb_strtolower($word), $particles, true);
            if ($isAcronym) {
                continue;
            }
            $lower = mb_strtolower($word);
            if ($index > 0 && in_array($lower, $particles, true)) {
                $words[$index] = $lower;

                continue;
            }
            $words[$index] = self::capitalizeWord($lower);
        }

        return implode(' ', $words);
    }

    /** Capitalise a lower-case word and the parts after a hyphen or apostrophe: "anne-marie", "o'neil", "mcdonald". */
    private static function capitalizeWord(string $word): string
    {
        $parts = preg_split("/([-'’])/u", $word, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$word];
        $result = '';
        $previous = '';
        foreach ($parts as $part) {
            if ($part === '-' || $part === "'" || $part === '’') {
                $result .= $part;
                $previous = $part;

                continue;
            }
            // A lone "s" or "t" after an apostrophe is a possessive or contraction ("Mike's", "Don't").
            if ($previous !== '' && $previous !== '-' && in_array($part, ['s', 't', 'd', 'll', 're', 've', 'm'], true)) {
                $result .= $part;
            } else {
                $result .= self::upperFirst(preg_replace_callback('/^mc(\p{L})/u', fn (array $m): string => 'mc'.mb_strtoupper($m[1]), $part) ?? $part);
            }
            $previous = $part;
        }

        return $result;
    }

    private static function upperFirst(string $word): string
    {
        return mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
    }
}
