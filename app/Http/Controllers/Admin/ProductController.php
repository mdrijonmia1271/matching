<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Services\ProductService;
use App\Support\Barcode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProductController extends Controller implements HasMiddleware
{
    public function __construct(protected ProductService $products) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index']),
            new Middleware('can:products.create', only: ['create', 'store']),
            new Middleware('can:products.edit', only: ['edit', 'update', 'destroyImage']),
            new Middleware('can:products.delete', only: ['destroy', 'restore']),
        ];
    }

    public function index(Request $request)
    {
        $products = Product::with(['category:id,name', 'subcategory:id,name', 'brand:id,name'])
            ->withCount('variants')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = trim((string) $request->input('q'));
                $like = '%' . $term . '%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('tags', 'like', $like)
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like))
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)->orWhere('barcode', $term)));
            })
            ->when($request->filled('category'), fn ($q) => $q->where(fn ($c) => $c
                ->where('category_id', $request->integer('category'))
                ->orWhere('subcategory_id', $request->integer('category'))))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand_id', $request->integer('brand')))
            ->when($request->input('stock') === 'low', fn ($q) => $q->whereHas('variants', fn ($v) => $v->active()->lowStock()))
            ->when($request->input('stock') === 'out', fn ($q) => $q->whereHas('variants', fn ($v) => $v->active()->outOfStock()))
            ->when($request->input('status') === 'archived', fn ($q) => $q->onlyTrashed())
            ->when($request->input('status') === 'hidden', fn ($q) => $q->where('is_active', false))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Category::topLevel()->with('children')->orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create()
    {
        return view('admin.products.form', [
            'product' => (new Product(['is_active' => true]))->setRelation('variants', collect()),
        ] + $this->formOptions());
    }

    public function store(Request $request)
    {
        [$data, $rows] = $this->validated($request);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        try {
            $product = $this->products->create($data, $rows, $request->boolean('generate_barcodes'));
        } catch (RuntimeException $e) {
            if (isset($data['image'])) {
                Storage::disk('public')->delete($data['image']);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->syncGallery($request, $product);

        AuditLogger::log('products', 'created', $product, 'Product ' . $product->name . ' created with ' . $product->variants()->count() . ' variant(s)',
            new: $product->only(['name', 'sku', 'category_id', 'cost_price', 'price', 'sale_price', 'is_active']));

        return redirect()->route('admin.products.index')->with('success', 'Product created.');
    }

    public function edit(Product $product)
    {
        return view('admin.products.form', [
            'product' => $product->load(['images', 'variants']),
        ] + $this->formOptions());
    }

    public function update(Request $request, Product $product)
    {
        [$data, $rows] = $this->validated($request, $product);

        $oldImage = $product->image;

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        try {
            $result = $this->products->update($product, $data, $rows, $request->boolean('generate_barcodes'));
        } catch (RuntimeException $e) {
            if (isset($data['image'])) {
                Storage::disk('public')->delete($data['image']);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        if (isset($data['image']) && $oldImage) {
            Storage::disk('public')->delete($oldImage);
        }

        $this->syncGallery($request, $product);

        [$old, $new] = $result['changes'];
        $variants = array_filter($result['variants']);

        if ($new || $variants) {
            $priceChanged = array_intersect_key($new, array_flip(['price', 'sale_price', 'cost_price'])) !== [];

            AuditLogger::log('products', $priceChanged ? 'price_changed' : 'updated', $product,
                'Product ' . $product->name . ($priceChanged ? ' price changed' : ' updated'),
                $old, $new + ($variants ? ['variants' => array_map(fn ($labels) => implode(', ', $labels), $variants)] : []));
        }

        return redirect()->route('admin.products.index')->with('success', 'Product updated.');
    }

    /**
     * Archive rather than delete: past orders and the stock ledger reference the
     * product. Images are kept so a restore brings it back intact.
     */
    public function destroy(Product $product)
    {
        $product->delete();

        AuditLogger::log('products', 'archived', $product, 'Product ' . $product->name . ' archived');

        return back()->with('success', $product->name . ' archived. It is hidden from the shop and can be restored.');
    }

    public function restore(Product $product)
    {
        $product->restore();

        AuditLogger::log('products', 'restored', $product, 'Product ' . $product->name . ' restored');

        return back()->with('success', $product->name . ' restored.');
    }

    public function destroyImage(ProductImage $image)
    {
        Storage::disk('public')->delete($image->path);
        $image->delete();

        return back()->with('success', 'Image removed.');
    }

    protected function formOptions(): array
    {
        return [
            'categories' => Category::topLevel()->with('children')->orderBy('sort_order')->orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    protected function validated(Request $request, ?Product $product = null): array
    {
        $hasVariants = $request->boolean('has_variants');

        $data = $request->validate([
            'category_id' => ['required', Rule::exists('categories', 'id')->whereNull('parent_id')],
            'subcategory_id' => ['nullable', Rule::exists('categories', 'id')->where('parent_id', (int) $request->input('category_id'))],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:200', 'alpha_dash', Rule::unique('products', 'slug')->ignore($product?->id)],
            'sku' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._\-\/]+$/', Rule::unique('products', 'sku')->ignore($product?->id),
                function (string $attribute, mixed $value, Closure $fail) use ($product) {
                    $taken = ProductVariant::withTrashed()->where('sku', $value)
                        ->when($product, fn ($q) => $q->where('product_id', '!=', $product->id))
                        ->exists();

                    if ($taken) {
                        $fail('This SKU is already used by a variant of another product.');
                    }
                }],
            'short_description' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'tags' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', 'image', 'max:2048'],
            'gallery' => ['nullable', 'array', 'max:' . Product::MAX_GALLERY_IMAGES,
                function (string $attribute, mixed $value, Closure $fail) use ($product) {
                    // The cap is on the gallery, not on one upload: six already there means no room for a seventh.
                    $room = Product::MAX_GALLERY_IMAGES - (int) $product?->images()->count();

                    if (count((array) $value) > $room) {
                        $fail($room > 0
                            ? "This product has room for $room more image(s). Remove some below first."
                            : 'This product already has ' . Product::MAX_GALLERY_IMAGES . ' gallery images. Remove one below first.');
                    }
                }],
            'gallery.*' => ['image', 'max:2048'],
            'image_order' => ['nullable', 'array', 'max:' . Product::MAX_GALLERY_IMAGES],
            'image_order.*' => ['integer'],
            'variants' => ['required', 'array', 'min:1', 'max:300'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.color' => ['nullable', 'string', 'max:40'],
            'variants.*.size' => ['nullable', 'string', 'max:40'],
            'variants.*.sku' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'distinct:ignore_case'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64', 'distinct'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'variants.*.sale_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'variants.*.opening_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'variants.*.is_active' => ['nullable', 'boolean'],
        ], [
            'variants.*.sku.distinct' => 'Two variants have the same SKU.',
            'variants.*.barcode.distinct' => 'Two variants have the same barcode.',
            'variants.*.sku.regex' => 'SKUs may only contain letters, numbers and . _ - /',
            'subcategory_id.exists' => 'The subcategory must belong to the selected category.',
        ]);

        $rows = array_values($data['variants']);
        unset($data['variants'], $data['gallery'], $data['image'], $data['image_order']);

        $data['tags'] = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn ($tag) => trim($tag))->filter()->unique()->take(20)->values()->all() ?: null;
        $data['has_variants'] = $hasVariants;
        $data['is_active'] = $request->boolean('is_active');
        $data['is_featured'] = $request->boolean('is_featured');
        $data['is_new_arrival'] = $request->boolean('is_new_arrival');

        $this->validateVariantRows($rows, $product, $hasVariants, (float) $data['price']);

        return [$data, $rows];
    }

    /** Cross-row and database checks the rule arrays cannot express. */
    protected function validateVariantRows(array $rows, ?Product $product, bool $hasVariants, float $productPrice): void
    {
        $errors = [];
        $ownIds = $product ? $product->variants()->pluck('id')->all() : [];
        $combinations = [];

        if (! $hasVariants && count($rows) > 1) {
            $errors['has_variants'] = 'This product has several variants. Remove the extra variants before turning sizes and colours off.';
        }

        foreach ($rows as $i => $row) {
            $n = 'Variant ' . ($i + 1);
            $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;

            if ($id && ! in_array($id, $ownIds, true)) {
                $errors["variants.$i.id"] = "$n does not belong to this product.";
            }

            if ($hasVariants) {
                $color = mb_strtolower(trim((string) ($row['color'] ?? '')));
                $size = mb_strtolower(trim((string) ($row['size'] ?? '')));

                if ($color === '' && $size === '') {
                    $errors["variants.$i.color"] = "$n needs a colour or a size.";
                } elseif (isset($combinations["$color|$size"])) {
                    $errors["variants.$i.size"] = "$n repeats a colour and size that is already listed.";
                }

                $combinations["$color|$size"] = true;
            }

            $fields = $hasVariants ? ['sku', 'barcode'] : ['barcode'];

            foreach ($fields as $field) {
                $value = trim((string) ($row[$field] ?? ''));

                if ($value !== '' && ProductVariant::withTrashed()->where($field, $value)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                    $errors["variants.$i.$field"] = "$n: " . strtoupper($field) . " $value is already used by another variant.";
                }
            }

            $barcode = trim((string) ($row['barcode'] ?? ''));

            if ($barcode !== '' && ($problem = Barcode::validationError($barcode))) {
                $errors["variants.$i.barcode"] = "$n: the barcode $problem.";
            }

            $regular = filled($row['price'] ?? null) ? (float) $row['price'] : $productPrice;

            if ($hasVariants && filled($row['sale_price'] ?? null) && (float) $row['sale_price'] >= $regular) {
                $errors["variants.$i.sale_price"] = "$n: the sale price must be lower than the selling price.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Saves the gallery in the order the form shows it: the images named in
     * `image_order` first (that is the order the thumbnails were dragged into),
     * anything the form did not mention after them, and the new uploads last.
     */
    protected function syncGallery(Request $request, Product $product): void
    {
        $order = array_map('intval', (array) $request->input('image_order', []));
        $images = $product->images()->get()->keyBy('id');
        $position = 0;

        // Only this product's images: an id from another product is simply ignored.
        foreach ($order as $id) {
            if ($image = $images->pull($id)) {
                $image->update(['sort_order' => $position++]);
            }
        }

        foreach ($images->sortBy('sort_order') as $image) {
            $image->update(['sort_order' => $position++]);
        }

        foreach ((array) $request->file('gallery', []) as $file) {
            $product->images()->create([
                'path' => $file->store('products', 'public'),
                'sort_order' => $position++,
            ]);
        }
    }
}
