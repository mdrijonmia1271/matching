@props(['product'])

@php
    $wishlisted = auth()->check()
        && auth()->user()->wishlists->contains('product_id', $product->id);

    $isNew = ! $product->on_sale && ($product->is_new_arrival || $product->created_at?->gt(now()->subDays(21)));

    // rating_avg comes from the listing query when it is available; fall back to
    // the accessor. A product with no reviews shows no star row at all.
    $rating = (float) ($product->rating_avg ?? $product->average_rating);
@endphp

<article class="group relative flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-600/5">
    <div class="relative aspect-[3/4] overflow-hidden bg-slate-100">
        <a href="{{ route('shop.show', $product) }}">
            <img src="{{ $product->image_url }}" alt="{{ $product->name }}" loading="lazy"
                 class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
        </a>

        <div class="absolute left-3 top-3 flex flex-col gap-1.5">
            @if($product->on_sale)
                <span class="pill bg-rose-600 text-white">-{{ $product->discount_percent }}%</span>
            @elseif($isNew)
                <span class="pill bg-emerald-500 text-white">New</span>
            @endif
        </div>

        {{-- Quick actions: visible on touch, slide in on hover for pointers --}}
        <div class="absolute right-3 top-3 flex flex-col gap-2 transition duration-300 md:translate-x-14 md:opacity-0 md:group-hover:translate-x-0 md:group-hover:opacity-100">
            @auth
                <form method="POST" action="{{ route('wishlist.toggle', $product) }}">
                    @csrf
                    <button type="submit" class="icon-btn {{ $wishlisted ? 'bg-rose-500 text-white' : '' }}"
                            title="{{ $wishlisted ? 'Remove from wishlist' : 'Save to wishlist' }}">
                        <svg class="h-4 w-4" fill="{{ $wishlisted ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.6-9.2-9A5 5 0 0 1 12 6.2 5 5 0 0 1 21.2 12C19 16.4 12 21 12 21z"/>
                        </svg>
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" class="icon-btn" title="Log in to save to wishlist">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.6-9.2-9A5 5 0 0 1 12 6.2 5 5 0 0 1 21.2 12C19 16.4 12 21 12 21z"/>
                    </svg>
                </a>
            @endauth

            @if($product->has_variants)
                {{-- Size and colour have to be chosen on the product page. --}}
                <a href="{{ route('shop.show', $product) }}" class="icon-btn" title="Choose options">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7z"/><path stroke-linecap="round" d="M9 7a3 3 0 0 1 6 0"/>
                    </svg>
                </a>
            @else
                <form method="POST" action="{{ route('cart.store', $product) }}">
                    @csrf
                    <button type="submit" class="icon-btn" title="Add to cart" @disabled(! $product->in_stock)>
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7z"/><path stroke-linecap="round" d="M9 7a3 3 0 0 1 6 0"/>
                        </svg>
                    </button>
                </form>
            @endif
        </div>

        @unless($product->in_stock)
            <div class="absolute inset-0 grid place-items-center bg-white/70">
                <span class="rounded-md bg-ink-900 px-3 py-1.5 text-xs font-semibold text-white">Out of stock</span>
            </div>
        @endunless
    </div>

    <div class="flex flex-1 flex-col p-4">
        @if($product->category)
            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-600">{{ $product->category->name }}</p>
        @endif

        <h3 class="mt-1.5 line-clamp-2 text-sm font-semibold text-slate-900">
            <a href="{{ route('shop.show', $product) }}" class="hover:text-brand-600">{{ $product->name }}</a>
        </h3>

        @if($rating > 0)
            <div class="mt-2">
                <x-stars :rating="$rating" size="h-3.5 w-3.5" />
            </div>
        @endif

        <div class="mt-3 flex items-baseline gap-2">
            <span class="text-base font-bold text-slate-900">@money($product->current_price)</span>
            @if($product->on_sale)
                <span class="text-xs text-slate-400 line-through">@money($product->price)</span>
            @endif
        </div>

        @if($product->has_variants)
            <a href="{{ route('shop.show', $product) }}" class="btn-primary relative mt-4 w-full {{ $product->in_stock ? '' : 'pointer-events-none opacity-60' }}">
                {{ $product->in_stock ? 'Choose options' : 'Unavailable' }}
            </a>
        @else
            <form method="POST" action="{{ route('cart.store', $product) }}" class="relative mt-4">
                @csrf
                <button type="submit" class="btn-primary w-full" @disabled(! $product->in_stock)>
                    {{ $product->in_stock ? 'Add to cart' : 'Unavailable' }}
                </button>
            </form>
        @endif
    </div>
</article>
