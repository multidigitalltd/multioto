<?php

namespace App\Support;

/**
 * The licence key itself: how one is made, how it is written down, and how it
 * is stored.
 *
 * Three decisions, each of them from the contract in docs/license-api.md:
 *
 *  · **A human types it.** Customers copy keys off an email, dictate them on
 *    the phone and read them back from a screenshot. `XXXX-XXXX-XXXX-XXXX`
 *    without the characters O, 0, I and 1 removes the confusions that produce
 *    support tickets, and anything typed loosely — lowercase, spaces, no
 *    dashes — still resolves to the same key.
 *
 *  · **It is never stored.** What is stored is an HMAC of it, which is also
 *    what a lookup matches on. A leaked database hands nobody a working key,
 *    and there is no screen in the panel that can show one after it was issued.
 *
 *  · **It is compared in constant time.** A plain string comparison answers
 *    faster the earlier it differs, and that difference is measurable across a
 *    network.
 */
class LicenseKey
{
    /**
     * No O, no 0, no I, no 1 — the four that get misread and mistyped. Thirty-two
     * characters over sixteen positions is eighty bits of key.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const GROUPS = 4;

    private const GROUP_SIZE = 4;

    /** A fresh key, in the form the customer will see it. */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $characters = '';

        for ($i = 0; $i < self::GROUPS * self::GROUP_SIZE; $i++) {
            $characters .= self::ALPHABET[random_int(0, $last)];
        }

        return self::format($characters);
    }

    /**
     * However it was typed, the one canonical form: upper case, only the
     * alphabet's characters, grouped with dashes.
     */
    public static function normalize(string $key): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key) ?? '');

        return self::format($clean);
    }

    private static function format(string $characters): string
    {
        return implode('-', str_split($characters, self::GROUP_SIZE));
    }

    /** What goes in the database, and what a lookup matches on. */
    public static function hash(string $key): string
    {
        return hash_hmac('sha256', self::normalize($key), self::secret());
    }

    /** The first group — enough to tell two licences apart, useless on its own. */
    public static function prefix(string $key): string
    {
        return substr(self::normalize($key), 0, self::GROUP_SIZE);
    }

    /** Constant-time comparison of two hashes. */
    public static function matches(string $storedHash, string $key): bool
    {
        return hash_equals($storedHash, self::hash($key));
    }

    /**
     * Does this even look like one of our keys? Cheap rejection of noise before
     * it reaches the database — and the answer to a typo is the same as the
     * answer to an unknown key, so nothing is revealed by it.
     */
    public static function looksValid(string $key): bool
    {
        return preg_match('/^['.self::ALPHABET.']{'.self::GROUP_SIZE.'}(-['.self::ALPHABET.']{'.self::GROUP_SIZE.'}){'.(self::GROUPS - 1).'}$/',
            self::normalize($key)) === 1;
    }

    private static function secret(): string
    {
        $secret = (string) config('licensing.secret');

        // An empty secret turns the HMAC into a plain public hash of the key and
        // the download signature into decoration. Better to fail loudly on the
        // first request than to run a licence server that only looks protected.
        if ($secret === '') {
            throw new \RuntimeException('חסר סוד לשרת הרישיונות (LICENSE_SERVER_SECRET). ללא סוד, המפתחות אינם מוגנים.');
        }

        return $secret;
    }
}
