<?php

namespace App\Support;

/**
 * Parse a free-text list of email addresses (comma / semicolon / newline
 * separated) into clean recipient arrays. Used wherever a setting may hold more
 * than one address — e.g. the team-notification recipients.
 */
class EmailList
{
    /** Split a raw list into trimmed, non-empty parts (valid or not). */
    private static function parts(?string $value): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[,;\n\r]+/', (string) $value) ?: [],
        ), fn (string $part): bool => $part !== ''));
    }

    /**
     * The valid email addresses in the list, de-duplicated.
     *
     * @return array<int, string>
     */
    public static function parse(?string $value): array
    {
        return array_values(array_unique(array_filter(
            self::parts($value),
            fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /**
     * Entries that are NOT valid email addresses (for error messages).
     *
     * @return array<int, string>
     */
    public static function invalid(?string $value): array
    {
        return array_values(array_filter(
            self::parts($value),
            fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) === false,
        ));
    }
}
