<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Finds variants by barcode, SKU or product name. A barcode scanner types the
 * code and presses Enter, so an exact barcode/SKU match is returned first.
 */
class VariantLookupController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, \Closure $next) {
                abort_unless($request->user()->canAny(['inventory.view', 'products.view', 'pos.sell']), 403);

                return $next($request);
            }),
        ];
    }

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q'));

        if (mb_strlen($term) < 2) {
            return response()->json(['exact' => false, 'results' => []]);
        }

        $exact = ProductVariant::with('product')->forLiveProducts()
            ->where(fn ($q) => $q->where('barcode', $term)->orWhere('sku', $term))
            ->first();

        $matches = ProductVariant::with('product')->forLiveProducts()
            ->search($term)
            ->when($exact, fn ($q) => $q->whereKeyNot($exact->id))
            ->orderBy('sku')
            ->limit(20)
            ->get();

        $results = collect($exact ? [$exact] : [])->merge($matches)->map->toLookupArray()->values();

        return response()->json(['exact' => (bool) $exact, 'results' => $results]);
    }
}
