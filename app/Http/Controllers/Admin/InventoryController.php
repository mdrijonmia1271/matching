<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Services\StockService;
use App\Support\CsvExport;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/** Stock on hand per variant, low / out of stock lists, stock counts and export. */
class InventoryController extends Controller implements HasMiddleware
{
    public const STATUSES = [
        'in_stock' => 'In stock',
        'low' => 'Low stock',
        'out' => 'Out of stock',
        'inactive' => 'Inactive',
    ];

    public const SORTS = [
        'name' => 'Product name',
        'stock_asc' => 'Stock: low to high',
        'stock_desc' => 'Stock: high to low',
        'value_desc' => 'Stock value: high to low',
    ];

    /** Most rows a single stock count screen loads. */
    public const COUNT_LIMIT = 500;

    public function __construct(protected StockService $stock) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.view', only: ['index', 'export']),
            new Middleware('can:reports.export', only: ['export']),
            new Middleware('can:inventory.adjust', only: ['count', 'storeCount']),
        ];
    }

    public function index(Request $request)
    {
        return view('admin.inventory.index', [
            'variants' => $this->filtered($request)->paginate(25)->withQueryString(),
            'summary' => $this->summary(),
        ] + $this->filterOptions());
    }

    public function export(Request $request)
    {
        $rows = (function () use ($request) {
            foreach ($this->filtered($request)->lazy(500) as $variant) {
                $product = $variant->product;
                $cost = $variant->effective_cost;

                yield [
                    $product->name,
                    $product->has_variants ? $variant->label : '',
                    $variant->sku,
                    (string) $variant->barcode,
                    (string) $product->category?->name,
                    (string) $product->brand?->name,
                    (int) $variant->stock,
                    $variant->low_stock_level,
                    self::STATUSES[self::statusOf($variant)],
                    $cost,
                    $cost !== null ? round($cost * max(0, $variant->stock), 2) : null,
                    $variant->current_price,
                ];
            }
        })();

        return CsvExport::download('inventory-' . now()->format('Y-m-d-His') . '.csv', [
            'Product', 'Variant', 'SKU', 'Barcode', 'Category', 'Brand', 'Stock', 'Low stock level',
            'Status', 'Purchase price', 'Stock value (cost)', 'Selling price',
        ], $rows);
    }

    public function count(Request $request)
    {
        $filtered = collect(['q', 'category', 'brand', 'status'])->contains(fn (string $key) => $request->filled($key))
            || $request->boolean('all');

        $variants = $filtered ? $this->filtered($request)->limit(self::COUNT_LIMIT + 1)->get() : collect();

        return view('admin.inventory.count', [
            'variants' => $variants->take(self::COUNT_LIMIT),
            'truncated' => $variants->count() > self::COUNT_LIMIT,
            'filtered' => $filtered,
        ] + $this->filterOptions());
    }

    /**
     * Records the differences found by a physical count.
     *
     * Each row carries the stock the screen showed when counting started. The
     * difference (counted − shown) is applied, not an absolute quantity, so a
     * sale made while the shelf was being counted is not undone.
     */
    public function storeCount(Request $request)
    {
        $data = $request->validate([
            'counts' => ['required', 'array', 'max:' . self::COUNT_LIMIT],
            'counts.*.variant_id' => ['required', 'integer', 'distinct'],
            'counts.*.expected' => ['required', 'integer'],
            'counts.*.counted' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            'counts.*.counted.min' => 'Counted quantities cannot be negative.',
        ]);

        $rows = collect($data['counts'])
            ->filter(fn (array $row) => ($row['counted'] ?? null) !== null && (int) $row['counted'] !== (int) $row['expected']);

        if ($rows->isEmpty()) {
            return back()->withInput()->with('warning', 'Nothing to record: every counted quantity matches the system.');
        }

        $variants = ProductVariant::with('product')->forLiveProducts()->whereIn('id', $rows->pluck('variant_id'))->get()->keyBy('id');
        $adjusted = [];

        DB::transaction(function () use ($rows, $variants, $data, &$adjusted) {
            foreach ($rows as $row) {
                $variant = $variants->get((int) $row['variant_id']);

                if (! $variant) {
                    continue;
                }

                $difference = (int) $row['counted'] - (int) $row['expected'];
                $note = trim('Stock count: counted ' . $row['counted'] . ', system showed ' . $row['expected'] . '. ' . ($data['note'] ?? ''));

                $movement = $this->stock->move($variant, $difference > 0 ? 'in' : 'out', abs($difference), 'adjustment', $note, allowNegative: true);

                $adjusted[] = $variant->sku . ': ' . $movement->stock_before . ' → ' . $movement->stock_after;
            }
        });

        AuditLogger::log('inventory', 'stock_count', null, 'Stock count recorded: ' . count($adjusted) . ' item(s) adjusted',
            new: array_filter(['items' => $adjusted, 'note' => $data['note'] ?? null]));

        return redirect()->route('admin.stock.index', ['reason' => 'adjustment', 'from' => today()->toDateString()])
            ->with('success', count($adjusted) . ' item(s) adjusted from the stock count.');
    }

    public static function statusOf(ProductVariant $variant): string
    {
        return (! $variant->is_active || ! $variant->product?->is_active) ? 'inactive' : $variant->stock_status;
    }

    /** Variants of non-archived products, joined to products for filtering and sorting. */
    protected function filtered(Request $request): Builder
    {
        $term = trim((string) $request->input('q'));
        $threshold = (int) Settings::get('low_stock_threshold', 5);
        $sellable = fn (Builder $query) => $query->where('product_variants.is_active', true)->where('products.is_active', true);

        $query = ProductVariant::query()
            ->select('product_variants.*')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->with(['product' => fn ($product) => $product->with(['category:id,name', 'brand:id,name'])])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match
                ->where('product_variants.sku', 'like', '%' . $term . '%')
                ->orWhere('product_variants.barcode', $term)
                ->orWhere('products.name', 'like', '%' . $term . '%')))
            ->when($request->filled('category'), fn (Builder $q) => $q->where(fn (Builder $match) => $match
                ->where('products.category_id', $request->integer('category'))
                ->orWhere('products.subcategory_id', $request->integer('category'))))
            ->when($request->filled('brand'), fn (Builder $q) => $q->where('products.brand_id', $request->integer('brand')));

        match ($request->input('status')) {
            'in_stock' => $sellable($query)->whereRaw('product_variants.stock > COALESCE(product_variants.low_stock_threshold, ?)', [$threshold]),
            'low' => $sellable($query)->where('product_variants.stock', '>', 0)
                ->whereRaw('product_variants.stock <= COALESCE(product_variants.low_stock_threshold, ?)', [$threshold]),
            'out' => $sellable($query)->where('product_variants.stock', '<=', 0),
            'inactive' => $query->where(fn (Builder $match) => $match->where('product_variants.is_active', false)->orWhere('products.is_active', false)),
            default => null,
        };

        $value = 'CASE WHEN product_variants.stock > 0 THEN product_variants.stock ELSE 0 END * COALESCE(product_variants.cost_price, products.cost_price, 0)';

        match ($request->input('sort')) {
            'stock_asc' => $query->orderBy('product_variants.stock'),
            'stock_desc' => $query->orderByDesc('product_variants.stock'),
            'value_desc' => $query->orderByRaw($value . ' DESC'),
            default => $query->orderBy('products.name')->orderBy('product_variants.sort_order'),
        };

        return $query->orderBy('product_variants.id');
    }

    /** Totals across the whole catalogue (not the current filter). */
    protected function summary(): array
    {
        $row = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereNull('p.deleted_at')
            ->whereNull('v.deleted_at')
            ->selectRaw('
                SUM(CASE WHEN v.stock > 0 THEN v.stock ELSE 0 END) AS units,
                SUM(CASE WHEN v.stock > 0 THEN v.stock * COALESCE(v.cost_price, p.cost_price, 0) ELSE 0 END) AS cost_value,
                SUM(CASE WHEN v.stock > 0 THEN v.stock * COALESCE(v.price, p.price) ELSE 0 END) AS retail_value,
                SUM(CASE WHEN v.is_active = 1 AND p.is_active = 1 AND v.stock > 0 AND v.stock <= COALESCE(v.low_stock_threshold, ?) THEN 1 ELSE 0 END) AS low,
                SUM(CASE WHEN v.is_active = 1 AND p.is_active = 1 AND v.stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                SUM(CASE WHEN v.stock > 0 AND v.cost_price IS NULL AND p.cost_price IS NULL THEN 1 ELSE 0 END) AS missing_cost
            ', [(int) Settings::get('low_stock_threshold', 5)])
            ->first();

        return [
            'units' => (int) $row->units,
            'cost_value' => (float) $row->cost_value,
            'retail_value' => (float) $row->retail_value,
            'low' => (int) $row->low,
            'out' => (int) $row->out_of_stock,
            'missing_cost' => (int) $row->missing_cost,
        ];
    }

    protected function filterOptions(): array
    {
        return [
            'categories' => Category::topLevel()->with('children')->orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'statuses' => self::STATUSES,
            'sorts' => self::SORTS,
        ];
    }
}
