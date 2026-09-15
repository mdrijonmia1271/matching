<?php

namespace App\Support;

use App\Models\ProductVariant;

/** Barcode validation and generation. Generated codes are in-store EAN-13 numbers. */
final class Barcode
{
    /** Characters that print and scan reliably as Code 128. */
    public const PATTERN = '/^[A-Za-z0-9\-\.\/\+ ]{3,64}$/';

    /** Why a barcode value is not acceptable, or null when it is fine. */
    public static function validationError(string $code): ?string
    {
        if (! preg_match(self::PATTERN, $code)) {
            return 'must be 3–64 characters using letters, numbers, spaces or - . / +';
        }

        if (preg_match('/^\d{13}$/', $code) && ! self::isValidEan13($code)) {
            return 'looks like an EAN-13 number but its check digit is wrong (please re-scan or re-type it)';
        }

        return null;
    }

    public static function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $twelveDigits[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public static function isValidEan13(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code)
            && self::ean13CheckDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    /**
     * A new EAN-13 that no variant uses. The leading "2" is the GS1 range
     * reserved for in-store codes, so it never clashes with manufacturer barcodes.
     */
    public static function generateUnique(): string
    {
        do {
            $body = '2' . str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
            $code = $body . self::ean13CheckDigit($body);
        } while (ProductVariant::withTrashed()->where('barcode', $code)->exists());

        return $code;
    }
}
