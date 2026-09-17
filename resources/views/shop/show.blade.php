@extends('layouts.app')

@section('title', $product->name)
@section('meta_description', Str::limit(strip_tags($product->short_description ?: $product->description), 150))

@section('content')
    @php
        $gallery = collect([$product->image_url])
            ->merge($product->images->map->url)
            ->values();

        $myReview = auth()->check() ? $product->reviews->firstWhere('user_id', auth()->id()) : null;
    @endphp

    <div class="mx-auto max-w-page px-4 py-8 sm:px-6">
        <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <a href="{{ route('home') }}" class="hover:text-brand-600">Home</a>
            <span>/</span>
            <a href="{{ route('shop.index') }}" class="hover:text-brand-600">Shop</a>
            @if($product->category)
                <span>/</span>
                <a href="{{ route('shop.index', ['category' => $product->category->slug]) }}" class="hover:text-brand-600">{{ $product->category->name }}</a>
            @endif
            <span>/</span>
            <span class="text-slate-700">{{ Str::limit($product->name, 40) }}</span>
        </nav>

        <div class="mt-6 grid gap-10 lg:grid-cols-2">
            <div x-data="{ active: 0 }">
                <div class="card overflow-hidden">
                    @foreach($gallery as $index => $url)
                        <img x-show="active === {{ $index }}" src="{{ $url }}" alt="{{ $product->name }}"
                             class="mx-auto aspect-[3/4] max-h-[620px] w-full object-cover" @if($index > 0) x-cloak @endif>
                    @endforeach
                </div>

                @if($gallery->count() > 1)
                    <div class="mt-3 flex gap-3 overflow-x-auto pb-1">
                        @foreach($gallery as $index => $url)
                            <button @click="active = {{ $index }}"
                                    x-bind:class="active === {{ $index }} ? 'ring-2 ring-brand-500' : 'ring-1 ring-slate-200'"
                                    class="h-20 w-20 shrink-0 overflow-hidden rounded-lg">
                                <img src="{{ $url }}" alt="" class="h-full w-full object-cover">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                @if($product->category)
                    <a href="{{ route('shop.index', ['category' => $product->category->slug]) }}"
                       class="text-xs font-semibold uppercase tracking-wide text-brand-600">{{ $product->category->name }}</a>
                @endif

                <h1 class="mt-2 text-3xl font-bold text-slate-900">{{ $product->name }}</h1>

                <div class="mt-3 flex flex-wrap items-center gap-4 text-sm text-slate-500">
                    @if($product->reviews->isNotEmpty())
                        <x-stars :rating="$product->average_rating" :count="$product->reviews->count()" />
                    @else
                        <a href="#reviews" class="hover:text-brand-600">Be the first to review</a>
                    @endif
                    <span>SKU: {{ $product->sku }}</span>
                </div>

                @php
                    $variantData = $product->variants->map(fn ($variant) => [
                        'id' => $variant->id,
                        'color' => $variant->color,
                        'size' => $variant->size,
                        'sku' => $variant->sku,
                        'price' => $variant->current_price,
                        'regular' => $variant->regular_price,
                        'stock' => max(0, $variant->stock),
                    ])->values();
                    $colors = $product->variants->pluck('color')->filter()->unique()->values();
                    $sizes = $product->variants->pluck('size')->filter()->unique()->values();
                    $lowLevel = (int) \App\Support\Settings::get('low_stock_threshold', 5);
                @endphp

                <div x-data="{
                        variants: @js($variantData),
                        hasVariants: @js($product->has_variants),
                        color: @js($colors->count() === 1 ? $colors->first() : null),
                        size: @js($sizes->count() === 1 ? $sizes->first() : null),
                        qty: 1,
                        symbol: @js(\App\Support\Money::symbol()),
                        get variant() {
                            if (! this.hasVariants) return this.variants[0] ?? null;
                            return this.variants.find(v => (v.color ?? null) === (this.color ?? null) && (v.size ?? null) === (this.size ?? null)) ?? null;
                        },
                        available(field, value) {
                            return this.variants.some(v => v[field] === value && v.stock > 0
                                && (field === 'color' ? (! this.size || v.size === this.size) : (! this.color || v.color === this.color)));
                        },
                        money(amount) {
                            return this.symbol + ' ' + Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        },
                        {{-- Percent off for the selected variant. No double quotes or --}}
                        {{-- angle brackets in here: this lives inside an HTML attribute. --}}
                        offLabel(sale, regular) {
                            if (! regular || ! (sale < regular)) return '';
                            const pct = Math.round((regular - sale) / regular * 10000) / 100;
                            return (pct % 1 === 0 ? pct : pct.toFixed(2)) + '% OFF';
                        },
                    }">
                    <div class="df-price mt-5">
                        <b x-text="variant ? money(variant.price) : @js(\App\Support\Money::format($product->current_price))">@money($product->current_price)</b>
                        <s x-show="variant ? variant.price < variant.regular : @js($product->on_sale)"
                           x-text="variant ? money(variant.regular) : @js(\App\Support\Money::format($product->price))">@money($product->price)</s>
                        <span class="df-off"
                              x-show="variant ? variant.price < variant.regular : @js($product->on_sale)"
                              x-text="variant ? offLabel(variant.price, variant.regular) : @js($product->discount_label)">{{ $product->discount_label }}</span>
                        <span class="df-price__sku" x-show="variant" x-text="'SKU ' + (variant ? variant.sku : '')"></span>
                    </div>

                    @if($product->short_description)
                        <p class="mt-4 text-slate-600">{{ $product->short_description }}</p>
                    @endif

                    @if($product->has_variants)
                        @if($colors->isNotEmpty())
                            <div class="mt-6">
                                <p class="text-sm font-semibold text-slate-900">Colour: <span class="font-normal text-slate-600" x-text="color ?? 'Choose'">Choose</span></p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach($colors as $colorOption)
                                        <button type="button" @click="color = @js($colorOption)"
                                                :class="color === @js($colorOption) ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-300 text-slate-700 hover:border-slate-400'"
                                                :style="available('color', @js($colorOption)) ? '' : 'opacity:.45;text-decoration:line-through'"
                                                class="rounded-lg border px-3 py-1.5 text-sm">{{ $colorOption }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if($sizes->isNotEmpty())
                            <div class="mt-4">
                                <p class="text-sm font-semibold text-slate-900">Size: <span class="font-normal text-slate-600" x-text="size ?? 'Choose'">Choose</span></p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach($sizes as $sizeOption)
                                        <button type="button" @click="size = @js($sizeOption)"
                                                :class="size === @js($sizeOption) ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-300 text-slate-700 hover:border-slate-400'"
                                                :style="available('size', @js($sizeOption)) ? '' : 'opacity:.45;text-decoration:line-through'"
                                                class="min-w-11 rounded-lg border px-3 py-1.5 text-sm">{{ $sizeOption }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif

                    <p class="mt-4 text-sm">
                        @if($product->has_variants)
                            <span x-show="! variant" class="text-slate-500">Choose {{ $colors->isNotEmpty() && $sizes->isNotEmpty() ? 'a colour and size' : ($colors->isNotEmpty() ? 'a colour' : 'a size') }} to check availability.</span>
                        @endif
                        <span x-show="variant && variant.stock > {{ $lowLevel }}" x-cloak class="font-semibold text-emerald-600">In stock</span>
                        <span x-show="variant && variant.stock > 0 && variant.stock <= {{ $lowLevel }}" x-cloak class="font-semibold text-amber-600" x-text="variant ? 'Only ' + variant.stock + ' left' : ''"></span>
                        <span x-show="variant && variant.stock <= 0" x-cloak class="font-semibold text-rose-600">Out of stock</span>
                        @if(! $product->has_variants && $product->variants->isEmpty())
                            <span class="font-semibold text-rose-600">Out of stock</span>
                        @endif
                    </p>

                    <form method="POST" action="{{ route('cart.store', $product) }}" class="mt-6 flex flex-wrap items-center gap-3">
                        @csrf
                        <input type="hidden" name="variant_id" :value="variant ? variant.id : ''">
                        <div class="flex items-center rounded-lg border border-slate-300">
                            <button type="button" @click="qty = Math.max(1, qty - 1)" class="px-3 py-2.5 text-slate-500 hover:text-slate-900">&minus;</button>
                            <input type="number" name="quantity" x-model="qty" min="1" :max="variant ? Math.max(1, variant.stock) : 1"
                                   class="w-14 border-0 bg-transparent p-0 text-center text-sm focus:ring-0" aria-label="Quantity">
                            <button type="button" @click="qty = Math.min(variant ? Math.max(1, variant.stock) : 1, qty + 1)" class="px-3 py-2.5 text-slate-500 hover:text-slate-900">+</button>
                        </div>

                        <button type="submit" class="btn-primary flex-1 sm:flex-none sm:px-8" :disabled="! variant || variant.stock <= 0"
                                x-text="! variant ? 'Choose options' : (variant.stock > 0 ? 'Add to cart' : 'Out of stock')">
                            Add to cart
                        </button>
                    </form>
                </div>

                @auth
                    <form method="POST" action="{{ route('wishlist.toggle', $product) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="btn-secondary">
                            {{ auth()->user()->wishlists->contains('product_id', $product->id) ? 'Remove from wishlist' : 'Save to wishlist' }}
                        </button>
                    </form>
                @endauth

                <dl class="mt-8 grid gap-3 border-t border-slate-200 pt-6 text-sm sm:grid-cols-2">
                    <div class="flex gap-2"><dt class="text-slate-500">Delivery</dt><dd class="font-medium text-slate-800">2&ndash;4 business days</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500">Payment</dt><dd class="font-medium text-slate-800">COD or online</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500">Returns</dt><dd class="font-medium text-slate-800">7 days</dd></div>
                    <div class="flex gap-2"><dt class="text-slate-500">Viewed</dt><dd class="font-medium text-slate-800">{{ $product->views }} times</dd></div>
                </dl>
            </div>
        </div>

        @if($product->description)
            <section class="card mt-12 p-6">
                <h2 class="text-lg font-bold text-slate-900">Product details</h2>
                <div class="prose prose-slate mt-3 max-w-none whitespace-pre-line text-sm text-slate-600">{{ $product->description }}</div>
            </section>
        @endif

        <section id="reviews" class="card mt-8 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-bold text-slate-900">Reviews ({{ $product->reviews->count() }})</h2>
                <x-stars :rating="$product->average_rating" />
            </div>

            @auth
                <form method="POST" action="{{ route('reviews.store', $product) }}" class="mt-6 rounded-lg bg-slate-50 p-5">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">{{ $myReview ? 'Update your review' : 'Write a review' }}</p>

                    <div class="mt-3" x-data="{ rating: {{ $myReview->rating ?? 5 }} }">
                        <div class="flex items-center gap-1">
                            @for($i = 1; $i <= 5; $i++)
                                <button type="button" @click="rating = {{ $i }}" class="p-0.5" aria-label="{{ $i }} star">
                                    <svg class="h-6 w-6" x-bind:class="rating >= {{ $i }} ? 'text-amber-400' : 'text-slate-300'" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9L10 1.5z"/>
                                    </svg>
                                </button>
                            @endfor
                        </div>
                        <input type="hidden" name="rating" x-model="rating">
                    </div>

                    <textarea name="comment" rows="3" class="input mt-3" placeholder="What did you think of this product?">{{ old('comment', $myReview->comment ?? '') }}</textarea>

                    <button type="submit" class="btn-primary mt-3">{{ $myReview ? 'Update review' : 'Submit review' }}</button>
                    <p class="mt-2 text-xs text-slate-500">Only customers who ordered this product can post a review.</p>
                </form>
            @else
                <p class="mt-4 text-sm text-slate-600">
                    <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:underline">Log in</a> to leave a review.
                </p>
            @endauth

            <div class="mt-6 divide-y divide-slate-100">
                @forelse($product->reviews->sortByDesc('created_at') as $review)
                    <div class="flex gap-4 py-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-bold text-brand-700">
                            {{ strtoupper(substr($review->user->name ?? '?', 0, 1)) }}
                        </span>
                        <div class="flex-1">
                            <div class="flex flex-wrap items-center gap-3">
                                <p class="text-sm font-semibold text-slate-900">{{ $review->user->name ?? 'Customer' }}</p>
                                <x-stars :rating="$review->rating" size="h-3.5 w-3.5" />
                                <span class="text-xs text-slate-400">{{ $review->created_at->diffForHumans() }}</span>
                            </div>
                            @if($review->comment)
                                <p class="mt-1.5 text-sm text-slate-600">{{ $review->comment }}</p>
                            @endif
                        </div>
                        @if(auth()->id() === $review->user_id)
                            <form method="POST" action="{{ route('reviews.destroy', $review) }}">
                                @csrf @method('DELETE')
                                <button class="text-xs text-rose-600 hover:underline">Delete</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="py-6 text-sm text-slate-500">No reviews yet. Be the first to share your thoughts.</p>
                @endforelse
            </div>
        </section>

        @if($related->isNotEmpty())
            <section class="mt-12">
                <h2 class="text-2xl font-bold text-slate-900">You may also like</h2>
                <div class="mt-6 grid grid-cols-2 gap-5 lg:grid-cols-4">
                    @foreach($related as $item)
                        <x-product-card :product="$item" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
