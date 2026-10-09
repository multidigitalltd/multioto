<?php

namespace App\Services\SiteAgent;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Validate the complete effective sale window using the shop's clock, before any write. */
final class ProductSaleWindow
{
    public const INVALID_ORDER = 'סיום המבצע חייב להיות אחרי תחילתו. לא הוכן שינוי עם מועדים סותרים.';

    public static function changesDates(array $fields): bool
    {
        return array_key_exists('sale_from', $fields) || array_key_exists('sale_to', $fields);
    }

    /** Untouched boundaries come from the fresh product; ending a sale clears both before explicit date edits. */
    public static function problem(array $fields, array $product): ?string
    {
        if (! self::changesDates($fields)) {
            return null;
        }
        $clearsSale = array_key_exists('sale_price', $fields) && $fields['sale_price'] === '';
        $dates = [];
        foreach (['sale_from', 'sale_to'] as $key) {
            if (! array_key_exists($key, $fields) && ! $clearsSale && ! array_key_exists($key, $product)) {
                return 'לא התקבלו מועדי המבצע הקיימים במלואם. יש לקרוא שוב את המוצר לפני שינוי התזמון.';
            }
            $dates[$key] = array_key_exists($key, $fields) ? $fields[$key] : ($clearsSale ? null : $product[$key]);
        }
        if (in_array($dates['sale_from'], ['', null], true) && in_array($dates['sale_to'], ['', null], true)) {
            return null;
        }
        try {
            // Never substitute the application/server timezone for a site's missing timezone.
            if (! is_string($product['timezone'] ?? null) || trim($product['timezone']) === '') {
                throw new InvalidArgumentException;
            }
            $zone = new DateTimeZone($product['timezone']);
            $start = self::instant($dates['sale_from'], $zone);
            $end = self::instant($dates['sale_to'], $zone);
        } catch (\Throwable) {
            return 'לא ניתן לאמת את מועדי המבצע באזור הזמן של האתר. יש לבדוק את אזור הזמן ואת התאריך והשעה; שעה חסרה או כפולה במעבר שעון אינה נבחרת אוטומטית.';
        }

        return $start !== null && $end !== null && $end <= $start ? self::INVALID_ORDER : null;
    }

    /** Match the plugin's strict date/minute inputs and its offset-bearing saved date receipts. */
    private static function instant(mixed $value, DateTimeZone $zone): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException;
        }
        foreach (['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s',
            'Y-m-d\TH:iP', 'Y-m-d\TH:i:sP', 'Y-m-d H:iP', 'Y-m-d H:i:sP'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors && ($errors['warning_count'] || $errors['error_count']))
                || $date->format($format) !== $value) {
                continue;
            }
            if (! str_contains($format, 'P') && self::ambiguous($date, $zone)) {
                throw new InvalidArgumentException;
            }

            return $date->getTimestamp();
        }

        throw new InvalidArgumentException;
    }

    /** A repeated local clock hour needs an explicit offset, just as on the WordPress plugin. */
    private static function ambiguous(DateTimeImmutable $date, DateTimeZone $zone): bool
    {
        $timestamp = $date->getTimestamp();
        $wall = $date->format('Y-m-d H:i:s');
        $offsets = [];
        foreach ((array) $zone->getTransitions($timestamp - 172800, $timestamp + 172800) as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }
        $utcWall = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, new DateTimeZone('UTC'))->getTimestamp();
        $matches = 0;
        foreach (array_keys($offsets) as $offset) {
            if ((new DateTimeImmutable('@'.($utcWall - $offset)))->setTimezone($zone)->format('Y-m-d H:i:s') === $wall
                && ++$matches > 1) {
                return true;
            }
        }

        return false;
    }
}
