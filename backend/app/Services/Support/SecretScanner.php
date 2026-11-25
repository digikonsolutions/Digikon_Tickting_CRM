<?php

namespace App\Services\Support;

/**
 * A soft check for text that looks like a password or an API key. It names the kind of thing found, never the value, so
 * the warning itself cannot leak it. Nothing is redacted: the person is warned, and the ticket is flagged for staff.
 */
class SecretScanner
{
    private const PATTERNS = [
        'password' => '/\b(?:password|passwd|pwd|passcode)\s*(?:is|:|=)\s*\S{4,}/i',
        'api_key' => '/\b(?:api[_\- ]?key|secret|token|bearer)\s*(?:is|:|=)\s*[A-Za-z0-9_\-\.]{12,}/i',
        'key_format' => '/\b(?:sk-[A-Za-z0-9]{20,}|pit-[0-9a-f\-]{20,}|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{30,})\b/',
        'jwt' => '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{5,}/',
        'private_key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    ];

    /** @return list<string> the kinds found, empty when the text looks fine */
    public function scan(?string ...$texts): array
    {
        $found = [];
        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }
            foreach (self::PATTERNS as $kind => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $found[$kind] = true;
                }
            }
        }

        return array_keys($found);
    }

    public const MESSAGE = 'This looks like it contains a password or an API key. Please remove it and tell us how to receive access securely instead.';
}
