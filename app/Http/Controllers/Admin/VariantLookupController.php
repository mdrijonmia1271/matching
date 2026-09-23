<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
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

        $variants = collect($exact ? [$exact] : [])->merge($matches);
        $lastCosts = $this->lastPurchaseCosts($variants->pluck('id')->all());

        $results = $variants->map(fn (ProductVariant $variant) => $variant->toLookupArray() + [
            'last_cost' => $lastCosts[$variant->id] ?? null,
        ])->values();

        return response()->json(['exact' => (bool) $exact, 'results' => $results]);
    }

    /**
     * What was last paid per unit for each variant on a received purchase, so
     * a new purchase can start from it when no purchase price is saved.
     *
     * @param  list<int>  $variantIds
     * @return array<int, float>
     */
    protected function lastPurchaseCosts(array $variantIds): array
    {
        if (! $variantIds) {
            return [];
        }

        return PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereIn('purchases.status', Purchase::COUNTED_STATUSES)
            ->whereIn('purchase_items.variant_id', $variantIds)
            ->orderByDesc('purchases.received_at')
            ->orderByDesc('purchase_items.id')
            ->get(['purchase_items.variant_id', 'purchase_items.unit_cost'])
            ->unique('variant_id')
            ->mapWithKeys(fn ($item) => [$item->variant_id => (float) $item->unit_cost])
            ->all();
    }
}
