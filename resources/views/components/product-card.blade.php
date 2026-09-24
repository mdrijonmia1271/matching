@props(['product'])

{{--
    The Dream Fashion product card. One definition used by the home page, the
    shop listing, the related products on a product page and the wishlist, so
    the grid looks the same everywhere.

    Variants matter here: a product with sizes/colours cannot be added straight
    to the cart, so it links to its page instead of posting a form that would
    only come back with "please choose a colour and size".
--}}
@php
    $wishlisted = auth()->check()
        && auth()->user()->wishlists->contains('product_id', $product->id);

    // rating_avg comes from the listing query when it is available; fall back to
    // the accessor. With no reviews at all the row still shows, unfilled.
    $rating = (float) ($product->rating_avg ?? $product->average_rating);
    $score = $rating > 0 ? (int) round($rating) : 0;
@endphp

<article class="df-card">
    <a href="{{ route('shop.show', $product) }}" class="df-card__media">
        @if($product->on_sale)
            <span class="df-card__badge">-{{ $product->discount_percent }}%</span>
        @endif

        <img src="{{ $product->image_url }}" alt="{{ $product->name }}" loading="lazy">

        @unless($product->in_stock)
            <span class="df-card__soldout">Out of stock</span>
        @endunless
    </a>

    <div class="df-card__body">
        <div class="df-stars" @if($score > 0) aria-label="{{ $score }} out of 5" @endif>
            @for($s = 1; $s <= 5; $s++)
                <svg viewBox="0 0 24 24" class="{{ $s <= $score ? 'is-on' : '' }}" fill="currentColor" aria-hidden="true">
                    <path d="m12 2 2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.3 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>
                </svg>
            @endfor
        </div>

        <h3 class="df-card__title">
            <a href="{{ route('shop.show', $product) }}">{{ $product->name }}</a>
        </h3>

        <p class="df-card__price">
            <b>@money($product->current_price)</b>
            @if($product->on_sale)
                <s>@money($product->price)</s>
                <span class="df-off">{{ $product->discount_label }}</span>
            @endif
        </p>

        @if(! $product->in_stock)
            <a href="{{ route('shop.show', $product) }}" class="df-btn df-btn--olive df-btn--sm is-muted">Unavailable</a>
        @elseif($product->has_variants)
            {{-- Size and colour have to be chosen on the product page. --}}
            <a href="{{ route('shop.show', $product) }}" class="df-btn df-btn--olive df-btn--sm df-btn--shine">Shop now</a>
        @else
            <form method="POST" action="{{ route('cart.store', $product) }}">
                @csrf
                <input type="hidden" name="quantity" value="1">
                <button type="submit" class="df-btn df-btn--olive df-btn--sm df-btn--shine">Add to Cart</button>
            </form>
        @endif

        @auth
            <form method="POST" action="{{ route('wishlist.toggle', $product) }}" class="df-card__wish">
                @csrf
                <button type="submit" class="{{ $wishlisted ? 'is-on' : '' }}"
                        title="{{ $wishlisted ? 'Remove from wishlist' : 'Save to wishlist' }}">
                    <svg viewBox="0 0 24 24" fill="{{ $wishlisted ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.6-9.2-9A5 5 0 0 1 12 6.2 5 5 0 0 1 21.2 12C19 16.4 12 21 12 21z"/>
                    </svg>
                </button>
            </form>
        @endauth
    </div>
</article>
