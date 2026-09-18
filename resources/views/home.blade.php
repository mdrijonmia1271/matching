@extends('layouts.app')

@section('title', 'Home')

@php
    /**
     * Six category tiles: a tall one on each side and a 2x2 block between them.
     * Categories come first; if there are fewer than six with a picture the rest
     * are filled from the catalogue, so the grid never renders with a hole in it.
     */
    $tiles = $categories
        ->map(fn ($category) => [
            'label' => $category->name,
            'url' => route('shop.index', ['category' => $category->slug]),
            'image' => $category->image ? asset('storage/' . $category->image) : null,
        ])
        ->filter(fn ($tile) => $tile['image'] !== null)
        ->take(6)
        ->values();

    if ($tiles->count() < 6) {
        $tiles = $tiles->concat(
            $latest->merge($featured)->unique('id')
                ->take(6 - $tiles->count())
                ->map(fn ($product) => [
                    'label' => $product->category?->name ?? $product->name,
                    'url' => route('shop.show', $product),
                    'image' => $product->image_url,
                ])
        )->values();
    }

@endphp

@section('content')

{{-- ============================================================
     HERO
     ============================================================ --}}
<section class="df-hero">
    <div class="df-hero__inner">
        <div class="df-hero__copy">
            <h1 class="df-hero__title">Fashion are unique</h1>
            <p class="df-hero__script">Trending winter collection</p>
            <a href="{{ route('shop.index') }}" class="df-btn df-btn--dark">Explore more</a>
        </div>
    </div>

    <div class="df-hero__figure">
        @if($featured->first() || $latest->first())
            <img src="{{ ($featured->first() ?? $latest->first())->image_url }}" alt="" class="df-hero__img">
        @endif
    </div>
</section>

{{-- ============================================================
     CATEGORIES
     ============================================================ --}}
<section class="df-section df-paper">
    <div class="df-wrap">
        <h2 class="df-heading">Categories</h2>

        <div class="df-cats-holder">
            <div class="df-cats">
                @foreach($tiles as $i => $tile)
                    <a href="{{ $tile['url'] }}" class="df-cat df-cat--{{ $i + 1 }}" aria-label="{{ $tile['label'] }}">
                        <img src="{{ $tile['image'] }}" alt="{{ $tile['label'] }}" loading="lazy">
                        <span class="df-cat__label">{{ $tile['label'] }}</span>
                    </a>
                @endforeach
            </div>

            <p class="df-vertical-script">Trending Collections</p>
        </div>
    </div>
</section>

{{-- ============================================================
     STATS
     ============================================================ --}}
<section class="df-stats-wrap">
    <div class="df-wrap df-stats">
        @foreach($stats as $stat)
            <div class="df-stat">
                <span class="df-stat__icon">
                    @switch($stat['icon'])
                        @case('box')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7.5 12 3 4 7.5v9L12 21l8-4.5v-9Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/></svg>
                            @break
                        @case('users')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><circle cx="17.5" cy="9" r="2.4"/><path d="M16 20a5 5 0 0 1 5-5"/></svg>
                            @break
                        @case('chart')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 20V12m7 8V5m7 15v-6"/><path d="M3 20h18"/></svg>
                            @break
                        @default
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.4"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
                    @endswitch
                </span>
                <p class="df-stat__label">{{ $stat['label'] }}</p>
                <p class="df-stat__value">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- ============================================================
     BEST SELLERS
     ============================================================ --}}
<section class="df-section df-paper" id="best-sellers"
         x-data="{ tab: @js(array_key_first($tabs)) }">
    <div class="df-wrap">
        <h2 class="df-heading">Best sellers</h2>
        <p class="df-sub">
            It was popularised in the 1960s with the release of Letraset sheets<br>
            containing Lorem Ipsum passages
        </p>

        <div class="df-tabs">
            @foreach($tabs as $label => $items)
                <button type="button" @click="tab = @js($label)"
                        class="df-tab" :class="tab === @js($label) && 'is-active'">{{ $label }}</button>
            @endforeach
        </div>

        @foreach($tabs as $label => $items)
            <div class="df-grid" x-show="tab === @js($label)" x-cloak>
                @foreach($items as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        @endforeach
    </div>
</section>

{{-- ============================================================
     BRANDS
     ============================================================ --}}
@if($brands->isNotEmpty())
    @php
        // The strip scrolls one whole group's width and snaps back, so the loop is
        // seamless only when both groups are identical. With few brands the group is
        // repeated until it is wide enough to fill the screen. Speed follows the
        // width, so two logos do not race past while twelve crawl.
        $perGroup = max(6, $brands->count());
        $marquee = collect()->pad((int) ceil($perGroup / $brands->count()), null)
            ->flatMap(fn () => $brands)->take($perGroup);
        $seconds = $perGroup * 4;
    @endphp

    <section class="df-brands" aria-label="Brands we carry">
        <div class="df-brands__viewport" style="--df-marquee: {{ $seconds }}s">
            @foreach([false, true] as $duplicate)
                <div class="df-brands__group" @if($duplicate) aria-hidden="true" @endif>
                    @foreach($marquee as $brand)
                        <span class="df-brand">
                            <img src="{{ $brand->logo_url }}" alt="{{ $duplicate ? '' : $brand->name }}" loading="lazy">
                        </span>
                    @endforeach
                </div>
            @endforeach
        </div>
    </section>
@endif

{{-- ============================================================
     INSTAGRAM COMMUNITY
     ============================================================ --}}
<section class="df-section df-paper">
    <div class="df-wrap">
        <h2 class="df-heading">Instagram Community</h2>

        <div class="df-insta">
            @foreach($gallery as $shot)
                <a href="{{ route('shop.show', $shot) }}" class="df-insta__item">
                    <img src="{{ $shot->image_url }}" alt="{{ $shot->name }}" loading="lazy">
                    <span class="df-insta__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="3.8"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor"/></svg>
                    </span>
                </a>
            @endforeach
        </div>

        <p class="df-insta__script">Check our Instagram...</p>
    </div>
</section>

{{-- ============================================================
     NEWSLETTER
     ============================================================ --}}
<section class="df-news-wrap">
    <div class="df-wrap">
        <div class="df-news">
            <h2 class="df-heading df-heading--sm">Newsletter</h2>
            <p class="df-sub">
                Adipiscing commodo elit at imperdiet dui accumsan sit amet nulla.<br>
                Platea dictumst vestibulum rhoncus pellentesque.
            </p>

            <form class="df-news__form" method="GET" action="{{ route('shop.index') }}">
                <input type="email" name="newsletter_email" placeholder="Your email address" aria-label="Your email address" required>
                <button type="submit" class="df-btn df-btn--olive df-btn--sm">Join Us</button>
            </form>

            <p class="df-news__note">Never miss out on new arrivals or special launches.</p>
        </div>
    </div>
</section>

@endsection
