<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * Readable variant SKUs such as KRT-BLK-M-026:
 * category code - colour code - size - product number.
 */
final class SkuGenerator
{
    protected const COLOURS = [
        'black' => 'BLK', 'white' => 'WHT', 'off white' => 'OWH', 'red' => 'RED', 'maroon' => 'MRN',
        'pink' => 'PNK', 'rose' => 'RSE', 'peach' => 'PCH', 'orange' => 'ORG', 'yellow' => 'YLW',
        'mustard' => 'MST', 'gold' => 'GLD', 'golden' => 'GLD', 'green' => 'GRN', 'mint' => 'MNT',
        'olive' => 'OLV', 'teal' => 'TEL', 'blue' => 'BLU', 'sky blue' => 'SKY', 'navy' => 'NVY',
        'navy blue' => 'NVY', 'purple' => 'PRP', 'lavender' => 'LVD', 'magenta' => 'MGT', 'grey' => 'GRY',
        'gray' => 'GRY', 'silver' => 'SLV', 'brown' => 'BRN', 'beige' => 'BGE', 'cream' => 'CRM',
        'multicolor' => 'MLT', 'multicolour' => 'MLT', 'multi' => 'MLT',
    ];

    public static function forVariant(Product $product, ?string $color, ?string $size, ?int $ignoreVariantId = null): string
    {
        $parts = array_filter([
            self::abbreviate($product->category?->name) ?: 'PRD',
            $color ? self::colourCode($color) : null,
            $size ? self::clean($size, 6) : null,
            str_pad((string) $product->id, 3, '0', STR_PAD_LEFT),
        ]);

        return self::unique(implode('-', $parts), $ignoreVariantId);
    }

    /** Appends -2, -3... until no other variant uses the SKU. */
    public static function unique(string $sku, ?int $ignoreVariantId = null): string
    {
        $candidate = $sku;
        $i = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->when($ignoreVariantId, fn ($q) => $q->whereKeyNot($ignoreVariantId))
            ->exists()) {
            $candidate = $sku . '-' . $i++;
        }

        return $candidate;
    }

    public static function colourCode(string $colour): string
    {
        return self::COLOURS[Str::lower(trim($colour))] ?? self::abbreviate($colour);
    }

    /** First letter plus the following consonants: "Kurti" → KRT, "Saree" → SRE. */
    public static function abbreviate(?string $value, int $length = 3): string
    {
        $letters = self::clean((string) $value, 50);

        if ($letters === '') {
            return '';
        }

        $consonants = $letters[0] . preg_replace('/[AEIOU]/', '', substr($letters, 1));

        return substr(strlen($consonants) >= $length ? $consonants : $letters, 0, $length);
    }

    protected static function clean(string $value, int $length): string
    {
        return substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($value))), 0, $length);
    }
}
