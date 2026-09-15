<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Support\Barcode;
use App\Support\BarcodeRenderer;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/** Pick variants, generate missing barcodes and print price/barcode labels. */
class BarcodeLabelController extends Controller implements HasMiddleware
{
    /** Label stock the print page supports. Sizes in millimetres. */
    public const SIZES = [
        'roll_38x25' => ['name' => 'Roll label 38 × 25 mm', 'width' => 38, 'height' => 25, 'bars' => 9, 'sheet' => false],
        'roll_50x30' => ['name' => 'Roll label 50 × 30 mm', 'width' => 50, 'height' => 30, 'bars' => 12, 'sheet' => false],
        'a4_3x8' => ['name' => 'A4 sheet, 24 labels (70 × 37 mm)', 'width' => 70, 'height' => 37, 'bars' => 14, 'sheet' => true],
    ];

    public const MAX_LABELS = 1000;

    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index', 'print']),
            new Middleware('can:products.edit', only: ['generate']),
        ];
    }

    public function index(Request $request)
    {
        $quantities = collect();

        // Selection comes back after a failed print, or from links on other screens.
        if ($old = $request->old('items')) {
            $quantities = collect($old)->mapWithKeys(fn ($item) => [(int) ($item['variant_id'] ?? 0) => max(1, (int) ($item['quantity'] ?? 1))]);
        } elseif ($request->filled('variants')) {
            $quantities = collect((array) $request->input('variants'))->mapWithKeys(fn ($id) => [(int) $id => 1]);
        }

        $variants = ProductVariant::with('product')->forLiveProducts()
            ->when($request->filled('product') && $quantities->isEmpty(),
                fn ($q) => $q->where('product_id', $request->integer('product'))->orderBy('sort_order'),
                fn ($q) => $q->whereIn('id', $quantities->keys()))
            ->get();

        $useStock = $request->boolean('stock_qty');

        return view('admin.barcodes.index', [
            'preselected' => $variants->map(fn (ProductVariant $variant) => $variant->toLookupArray() + [
                'quantity' => $quantities[$variant->id] ?? ($useStock ? max(1, $variant->stock) : 1),
            ])->values(),
            'sizes' => self::SIZES,
            'missingCount' => ProductVariant::forLiveProducts()->whereNull('barcode')->count(),
        ]);
    }

    public function print(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'size' => ['required', Rule::in(array_keys(self::SIZES))],
            'show_price' => ['nullable', 'boolean'],
        ], [
            'items.required' => 'Add at least one product to print labels for.',
        ]);

        $quantities = collect($data['items'])->mapWithKeys(fn ($item) => [(int) $item['variant_id'] => (int) $item['quantity']]);

        if ($quantities->sum() > self::MAX_LABELS) {
            return back()->withInput()->with('error', 'You can print up to ' . self::MAX_LABELS . ' labels at a time.');
        }

        $variants = ProductVariant::with('product')->whereIn('id', $quantities->keys())->get()->keyBy('id');
        $missing = $variants->filter(fn (ProductVariant $variant) => blank($variant->barcode));

        if ($missing->isNotEmpty()) {
            return back()->withInput()->with('error', 'These items have no barcode yet: '
                . $missing->map->full_name->implode(', ') . '. Generate their barcodes first.');
        }

        $labels = [];

        foreach ($quantities as $id => $quantity) {
            $variant = $variants[$id];

            try {
                $svg = BarcodeRenderer::svg($variant->barcode);
            } catch (Throwable) {
                return back()->withInput()->with('error', 'The barcode "' . $variant->barcode . '" on ' . $variant->full_name . ' cannot be printed. Correct it on the product form.');
            }

            // Draw once per variant, repeat for each copy.
            $labels = array_merge($labels, array_fill(0, $quantity, ['variant' => $variant, 'svg' => $svg]));
        }

        return view('admin.barcodes.print', [
            'labels' => $labels,
            'size' => self::SIZES[$data['size']],
            'showPrice' => $request->boolean('show_price'),
            'storeName' => Settings::get('store_name'),
        ]);
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['selected', 'product', 'all_missing'])],
            'variant_ids' => ['required_if:scope,selected', 'array'],
            'variant_ids.*' => ['integer'],
            'product_id' => ['required_if:scope,product', 'integer'],
        ]);

        $generated = DB::transaction(function () use ($data) {
            $variants = ProductVariant::forLiveProducts()
                ->whereNull('barcode')
                ->when($data['scope'] === 'selected', fn ($q) => $q->whereIn('id', $data['variant_ids']))
                ->when($data['scope'] === 'product', fn ($q) => $q->where('product_id', $data['product_id']))
                ->lockForUpdate()
                ->get();

            foreach ($variants as $variant) {
                $variant->update(['barcode' => Barcode::generateUnique()]);
            }

            return $variants->count();
        });

        if ($generated) {
            AuditLogger::log('products', 'barcodes_generated', null, 'Generated ' . $generated . ' barcode(s)',
                new: array_filter(['scope' => $data['scope'], 'product_id' => $data['product_id'] ?? null, 'count' => $generated]));
        }

        $redirect = match ($data['scope']) {
            'selected' => redirect()->route('admin.barcodes.index', ['variants' => $data['variant_ids']]),
            'product' => redirect()->route('admin.barcodes.index', ['product' => $data['product_id']]),
            default => back(),
        };

        return $generated
            ? $redirect->with('success', 'Generated ' . $generated . ' new barcode(s).')
            : $redirect->with('warning', 'Every selected item already has a barcode.');
    }
}
