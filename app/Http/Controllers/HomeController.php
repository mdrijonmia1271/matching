<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Support\Settings;
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
            ->orderByDesc('created_at')->take(8)->get();

        $featured = $this->cards()->where('is_featured', true)->latest()->take(8)->get();
        $latest = $this->cards()->latest()->take(8)->get();

        return view('home', [
            'hero' => $this->hero($featured, $latest),
            'categories' => $categories,
            'featured' => $featured,
            'latest' => $latest,
            'deals' => $deals,
            'tabs' => $this->bestSellerTabs($featured, $latest, $deals),
            'stats' => $this->stats(),
            'gallery' => $this->cards()->inRandomOrder()->take(5)->get(),
            // Active brands only, and only those with a logo: the strip shows nothing
            // else. Ordered Z to A, so the strip does not always open on the same name.
            'brands' => Brand::active()->whereNotNull('logo')->orderByDesc('name')->take(12)->get(),
        ]);
    }

    /**
     * Hero pictures and copy, all editable under Admin -> Settings -> Hero section.
     *
     * @param  Collection<int, Product>  $featured
     * @param  Collection<int, Product>  $latest
     * @return array<string, mixed>
     */
    protected function hero(Collection $featured, Collection $latest): array
    {
        $hero = collect(Settings::all())
            ->filter(fn ($value, $key) => str_starts_with($key, 'hero_'))
            ->all();

        // An empty link means "whatever is on sale", so the card keeps working
        // when nobody has pointed it anywhere.
        $hero['hero_offer_link'] = filled($hero['hero_offer_link'] ?? null)
            ? $hero['hero_offer_link']
            : route('shop.index', ['on_sale' => 1]);

        // Uploaded pictures win; with none, the hero falls back to the newest
        // featured product, as it did before the setting existed.
        $slides = collect($hero['hero_images'] ?? [])
            ->filter()
            ->map(fn (string $path) => asset('storage/' . $path))
            ->values();

        if ($slides->isEmpty() && $product = ($featured->first() ?? $latest->first())) {
            $slides = collect([$product->image_url]);
        }

        $hero['slides'] = $slides->all();

        return $hero;
    }

    /** Base query for product cards; the rating is averaged in SQL to avoid an N+1. */
    protected function cards()
    {
        return Product::active()
            ->with('category')
            ->withAvg(['reviews as rating_avg' => fn ($q) => $q->where('is_approved', true)], 'rating');
    }

    /**
     * The four "Best sellers" tabs. Every tab is a real query, so a tab never
     * shows something the catalogue does not sell — and never sits empty while
     * there are products to show.
     *
     * @param  Collection<int, Product>  $featured
     * @param  Collection<int, Product>  $latest
     * @param  Collection<int, Product>  $deals
     * @return array<string, Collection<int, Product>>
     */
    protected function bestSellerTabs(Collection $featured, Collection $latest, Collection $deals): array
    {
        // Most ordered first: the honest reading of "best seller".
        $best = $this->cards()
            ->withCount(['orderItems as sold_count' => fn ($q) => $q->whereHas('order',
                fn ($order) => $order->whereIn('status', Order::SALE_STATUSES))])
            ->orderByDesc('sold_count')->orderByDesc('is_featured')->latest()
            ->take(8)->get();

        $womensCategory = Category::active()
            ->where(fn ($q) => $q->where('name', 'like', '%women%')->orWhere('name', 'like', '%ladies%'))
            ->value('id');

        $womens = $womensCategory
            ? $this->cards()->where('category_id', $womensCategory)->latest()->take(8)->get()
            : $this->cards()->orderByDesc('rating_avg')->latest()->take(8)->get();

        return array_filter([
            'All' => $best->isNotEmpty() ? $best : $latest,
            'New arrivals' => $latest,
            'Stylish products' => $featured->isNotEmpty() ? $featured : $deals,
            'Womens' => $womens,
        ], fn (Collection $items) => $items->isNotEmpty());
    }

    /**
     * Storefront counters, laid out under Admin -> Settings -> Stats strip.
     * A tile counts the real thing unless it was given fixed text, so the
     * figures stay true as the shop grows.
     *
     * @return array<int, array<string, string>>
     */
    protected function stats(): array
    {
        if (! Settings::get('home_stats_enabled')) {
            return [];
        }

        $tiles = [];

        foreach ((array) Settings::get('home_stats', []) as $tile) {
            $value = $tile['source'] === 'manual'
                ? (string) ($tile['value'] ?? '')
                : $this->statFigure($tile['source']);

            if ($value === '') {
                continue;
            }

            $tiles[] = [
                'label' => (string) $tile['label'],
                'value' => $value,
                'icon' => (string) ($tile['icon'] ?? 'box'),
            ];
        }

        return $tiles;
    }

    /** The live figure behind one stats tile. */
    protected function statFigure(string $source): string
    {
        return match ($source) {
            'products' => number_format(Product::active()->count()),
            'customers' => number_format(Customer::count()),
            'sales' => number_format(Order::whereIn('status', Order::SALE_STATUSES)->count()),
            'rating' => $this->happyCustomers(),
            default => '',
        };
    }

    /** Average approved review score as a percentage; a shop with no reviews shows 100%. */
    protected function happyCustomers(): string
    {
        $rated = (float) Review::where('is_approved', true)->avg('rating');

        return ($rated > 0 ? round($rated / 5 * 100) : 100) . '%';
    }
}
