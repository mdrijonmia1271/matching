<?php

namespace App\Support;

/**
 * Bangladeshi mobile numbers in one shape (01XXXXXXXXX), so a customer is
 * found however the number was typed: +880 1712-345678, 8801712345678 or
 * 1712345678 all become 01712345678. Other numbers keep their digits only.
 */
class Phone
{
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        if (preg_match('/^(?:00)?880?(1\d{9})$/', $digits, $match) || preg_match('/^0?(1\d{9})$/', $digits, $match)) {
            return '0' . $match[1];
        }

        return substr($digits, 0, 20);
    }

    /** Whether a search term looks like (part of) a phone number rather than a name. */
    public static function looksLikePhone(string $term): bool
    {
        return (bool) preg_match('/^[\d\s+\-()]{3,}$/', trim($term));
    }
}
