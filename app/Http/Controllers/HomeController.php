<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

class HomeController extends Controller
{
    public function index()
    {
        $categories = Category::active()->topLevel()
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('name')->take(10)->get();

        $deals = $this->cards()
            ->whereNotNull('sale_price')
            ->whereColumn('sale_price', '<', 'price')
            ->orderByDesc('created_at')->take(6)->get();

        return view('home', [
            'categories' => $categories,
            'featured' => $this->cards()->where('is_featured', true)->latest()->take(8)->get(),
            'latest' => $this->cards()->latest()->take(8)->get(),
            'deals' => $deals,
            'slides' => $this->heroSlides($deals),
        ]);
    }

    /** Base query for product cards; the rating is averaged in SQL to avoid an N+1. */
    protected function cards()
    {
        return Product::active()
            ->with('category')
            ->withAvg(['reviews as rating_avg' => fn ($q) => $q->where('is_approved', true)], 'rating');
    }

    /**
     * Hero slides are built from real discounted products, so the banner never
     * advertises something the catalogue does not actually sell.
     *
     * @param  Collection<int, Product>  $deals
     * @return Collection<int, array<string, mixed>>
     */
    protected function heroSlides(Collection $deals): Collection
    {
        return $deals->sortByDesc('discount_percent')
            ->unique('category_id')
            ->take(3)
            ->values()
            ->map(fn (Product $product, int $index) => [
                'eyebrow' => $index === 0 ? 'New Collection ' . now()->year : $product->category?->name,
                'title' => $product->category?->name ?? 'New Arrivals',
                'subtitle' => $index === 0 ? 'picked for you' : 'now on offer',
                'text' => $product->short_description,
                'product' => $product,
            ]);
    }
}
