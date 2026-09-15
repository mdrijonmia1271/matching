<?php

namespace App\Support;

use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeEan13;

/** Draws barcodes as inline SVG for label printing. */
final class BarcodeRenderer
{
    /** EAN-13 for valid 13-digit codes (retail standard), Code 128 for everything else. */
    public static function symbology(string $code): string
    {
        return Barcode::isValidEan13($code) ? 'EAN-13' : 'Code 128';
    }

    /**
     * One SVG unit per bar module; `preserveAspectRatio="none"` lets CSS stretch
     * it to the label width while every bar keeps its relative width.
     */
    public static function svg(string $code, int $height = 40): string
    {
        $barcode = self::symbology($code) === 'EAN-13'
            ? (new TypeEan13)->getBarcode($code)
            : (new TypeCode128)->getBarcode($code);

        $svg = (new SvgRenderer)
            ->setSvgType(SvgRenderer::TYPE_SVG_INLINE)
            ->render($barcode, $barcode->getWidth(), $height);

        return str_replace('<svg ', '<svg preserveAspectRatio="none" role="img" aria-label="Barcode ' . e($code) . '" ', $svg);
    }
}
