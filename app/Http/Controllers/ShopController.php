<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::active()
            ->with('category')
            ->withAvg(['reviews as rating_avg' => fn ($q) => $q->where('is_approved', true)], 'rating')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = trim((string) $request->input('q'));
                $like = '%' . $term . '%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('short_description', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('tags', 'like', $like)
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like))
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)->orWhere('barcode', $term)));
            })
            // A category slug matches products in that category or filed under it as a subcategory.
            ->when($request->filled('category'), fn ($q) => $q->where(fn ($c) => $c
                ->whereHas('category', fn ($cat) => $cat->where('slug', $request->string('category')))
                ->orWhereHas('subcategory', fn ($sub) => $sub->where('slug', $request->string('category')))))
            ->when($request->filled('min_price'), fn ($q) => $q->where('price', '>=', (float) $request->input('min_price')))
            ->when($request->filled('max_price'), fn ($q) => $q->where('price', '<=', (float) $request->input('max_price')))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where('stock', '>', 0))
            ->when($request->boolean('on_sale'), fn ($q) => $q->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'price'))
            ->tap(fn ($q) => $this->applySort($q, $request->input('sort')))
            ->paginate(12)
            ->withQueryString();

        return view('shop.index', [
            'products' => $products,
            'categories' => Category::active()->topLevel()->withCount(['products' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function show(Product $product)
    {
        abort_unless($product->is_active, 404);

        $product->load([
            'category', 'subcategory', 'brand', 'images', 'reviews.user',
            'variants' => fn ($q) => $q->where('is_active', true),
        ]);
        $product->increment('views');

        $related = Product::active()
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->inRandomOrder()
            ->take(4)
            ->get();

        return view('shop.show', compact('product', 'related'));
    }

    protected function applySort($query, ?string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'name' => $query->orderBy('name'),
            'popular' => $query->orderByDesc('views'),
            'rating' => $query->orderByDesc('rating_avg'),
            default => $query->latest(),
        };
    }
}
