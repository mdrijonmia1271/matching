<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\StockService;
use App\Support\CsvExport;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class StockController extends Controller implements HasMiddleware
{
    public function __construct(protected StockService $stock) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.view', only: ['index', 'show', 'export']),
            new Middleware('can:reports.export', only: ['export']),
            new Middleware('can:inventory.adjust', only: ['create', 'store']),
        ];
    }

    public function index(Request $request)
    {
        $movements = $this->movements($request)
            ->with([
                'product:id,name,slug,sku,has_variants,deleted_at',
                'variant:id,product_id,sku,size,color,deleted_at',
                'user:id,name',
                'order:id,order_number',
            ])
            ->paginate(20)
            ->withQueryString();

        $today = StockMovement::whereDate('created_at', today())
            ->selectRaw('type, SUM(quantity) as units')
            ->groupBy('type')
            ->pluck('units', 'type');

        $live = fn () => ProductVariant::forLiveProducts()->active();

        return view('admin.stock.index', [
            'movements' => $movements,
            'product' => $request->filled('product') ? Product::withTrashed()->find($request->integer('product')) : null,
            'variant' => $request->filled('variant') ? ProductVariant::withTrashed()->with('product')->find($request->integer('variant')) : null,
            'reasons' => StockMovement::reasonLabels(),
            'stats' => [
                'units' => (int) ProductVariant::forLiveProducts()->where('stock', '>', 0)->sum('stock'),
                'value' => (float) DB::table('product_variants as v')
                    ->join('products as p', 'p.id', '=', 'v.product_id')
                    ->whereNull('p.deleted_at')->whereNull('v.deleted_at')->where('v.stock', '>', 0)
                    ->selectRaw('SUM(v.stock * COALESCE(v.cost_price, p.cost_price, 0)) as value')
                    ->value('value'),
                'missing_cost' => DB::table('product_variants as v')
                    ->join('products as p', 'p.id', '=', 'v.product_id')
                    ->whereNull('p.deleted_at')->whereNull('v.deleted_at')->where('v.stock', '>', 0)
                    ->whereNull('v.cost_price')->whereNull('p.cost_price')
                    ->count(),
                'low' => $live()->lowStock()->count(),
                'out' => $live()->outOfStock()->count(),
                'today_in' => (int) ($today['in'] ?? 0),
                'today_out' => (int) ($today['out'] ?? 0),
            ],
        ]);
    }

    public function show(StockMovement $movement)
    {
        return view('admin.stock.show', [
            'movement' => $movement->load(['product', 'variant', 'user:id,name,email', 'order:id,order_number', 'reference']),
        ]);
    }

    public function export(Request $request)
    {
        $rows = (function () use ($request) {
            $query = $this->movements($request)->with([
                'product:id,name,has_variants,deleted_at',
                'variant:id,product_id,sku,size,color,deleted_at',
                'user:id,name',
                'order:id,order_number',
            ]);

            foreach ($query->lazy(500) as $movement) {
                yield [
                    $movement->created_at?->format('Y-m-d H:i'),
                    (string) $movement->product?->name,
                    $movement->product?->has_variants ? (string) $movement->variant?->label : '',
                    (string) $movement->variant?->sku,
                    $movement->type === 'in' ? 'In' : 'Out',
                    $movement->type === 'in' ? $movement->quantity : -$movement->quantity,
                    $movement->stock_before,
                    $movement->stock_after,
                    $movement->reason_label,
                    $movement->unit_cost !== null ? (float) $movement->unit_cost : null,
                    (string) $movement->order?->order_number,
                    (string) $movement->note,
                    $movement->user?->name ?? ($movement->order ? 'Customer' : 'System'),
                ];
            }
        })();

        return CsvExport::download('stock-history-' . now()->format('Y-m-d-His') . '.csv', [
            'Date', 'Product', 'Variant', 'SKU', 'Direction', 'Quantity', 'Stock before', 'Stock after',
            'Reason', 'Unit cost', 'Order', 'Note', 'By',
        ], $rows);
    }

    public function create(Request $request)
    {
        $variantId = $request->old('variant_id') ?? $request->integer('variant');

        // Coming from a product without options: preselect its only variant.
        if (! $variantId && $request->filled('product')) {
            $variants = ProductVariant::where('product_id', $request->integer('product'))->pluck('id');
            $variantId = $variants->count() === 1 ? $variants->first() : null;
        }

        $selected = $variantId ? ProductVariant::with('product')->find($variantId) : null;

        return view('admin.stock.create', [
            'selected' => $selected?->toLookupArray(),
            'type' => $request->old('type', $request->input('type') === 'out' ? 'out' : 'in'),
            'allowNegative' => (bool) Settings::get('allow_negative_stock', false),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'variant_id' => ['required', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::in(['in', 'out'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', Rule::in(array_keys(
                $request->input('type') === 'out' ? StockMovement::OUT_REASONS : StockMovement::IN_REASONS
            ))],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'variant_id.required' => 'Scan or search for the product first.',
        ]);

        $variant = ProductVariant::with('product')->findOrFail($data['variant_id']);

        try {
            $movement = $this->stock->move($variant, $data['type'], $data['quantity'], $data['reason'], $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        AuditLogger::log('inventory', 'stock_adjusted', $variant,
            sprintf('Stock %s %d × %s (%s)', $movement->type, $movement->quantity, $variant->full_name, $movement->reason_label),
            ['stock' => $movement->stock_before],
            ['stock' => $movement->stock_after, 'reason' => $movement->reason, 'note' => $movement->note]);

        $message = sprintf(
            'Stock %s: %s%d × %s (now %d).',
            $movement->type,
            $movement->type === 'in' ? '+' : '−',
            $movement->quantity,
            $variant->full_name,
            $movement->stock_after
        );

        return $request->boolean('another')
            ? redirect()->route('admin.stock.create', ['type' => $data['type']])->with('success', $message)
            : redirect()->route('admin.stock.index')->with('success', $message);
    }

    /** Stock history filtered from the query string, newest first. */
    protected function movements(Request $request): Builder
    {
        return StockMovement::query()
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = trim((string) $request->input('q'));
                $like = '%' . $term . '%';
                $query->where(fn (Builder $q) => $q
                    ->whereHas('product', fn ($p) => $p->withTrashed()->where('name', 'like', $like))
                    ->orWhereHas('variant', fn ($v) => $v->withTrashed()->where('sku', 'like', $like)->orWhere('barcode', $term)));
            })
            ->when($request->filled('product'), fn ($q) => $q->where('product_id', $request->integer('product')))
            ->when($request->filled('variant'), fn ($q) => $q->where('variant_id', $request->integer('variant')))
            ->when(in_array($request->input('type'), ['in', 'out'], true), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('reason'), fn ($q) => $q->where('reason', $request->string('reason')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest('id');
    }
}
