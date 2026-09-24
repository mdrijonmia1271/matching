@extends('layouts.app')

@section('title', 'Home')

@php
    /**
     * Six category tiles: a tall one on each side and a 2x2 block between them.
     * Categories come first; if there are fewer than six with a picture the rest
     * are filled from the catalogue, so the grid never renders with a hole in it.
     */
    $tiles = $categories
        ->map(
            fn($category) => [
                'label' => $category->name,
                'url' => route('shop.index', ['category' => $category->slug]),
                'image' => $category->image ? asset('storage/' . $category->image) : null,
            ],
        )
        ->filter(fn($tile) => $tile['image'] !== null)
        ->take(6)
        ->values();

    if ($tiles->count() < 6) {
        $tiles = $tiles
            ->concat(
                $latest
                    ->merge($featured)
                    ->unique('id')
                    ->take(6 - $tiles->count())
                    ->map(
                        fn($product) => [
                            'label' => $product->category?->name ?? $product->name,
                            'url' => route('shop.show', $product),
                            'image' => $product->image_url,
                        ],
                    ),
            )
            ->values();
    }

@endphp

@section('content')

    {{-- ============================================================
     HERO
     ============================================================ --}}
    @php $slideCount = count($hero['slides']); @endphp

    {{-- With several pictures the hero runs itself, and the dots let a visitor
     take over: picking one restarts the clock so it does not jump straight away. --}}
    <section class="df-hero"
        @if ($slideCount > 1) x-data="{
            slide: 0,
            slides: {{ $slideCount }},
            timer: null,
            play() { this.timer = setInterval(() => this.slide = (this.slide + 1) % this.slides, 5000) },
            show(index) { this.slide = index; clearInterval(this.timer); this.play() },
        }"
        x-init="play()" @endif>
        <div class="df-hero__inner">
            <div class="df-hero__copy">
                <h1 class="df-hero__title">{{ $hero['hero_title'] }}</h1>
                @if (filled($hero['hero_subtitle']))
                    <p class="df-hero__script">{{ $hero['hero_subtitle'] }}</p>
                @endif
                <a href="{{ route('shop.index') }}" class="df-btn df-btn--dark df-btn--shine">Explore more</a>
            </div>

            @if ($hero['hero_offer_enabled'])
                <aside class="df-offer">
                    @if (filled($hero['hero_offer_kicker']))
                        <p class="df-offer__kicker">{{ $hero['hero_offer_kicker'] }}</p>
                    @endif
                    <p class="df-offer__value">{{ $hero['hero_offer_value'] }}<span>{{ $hero['hero_offer_suffix'] }}</span>
                    </p>
                    @if (filled($hero['hero_offer_off']))
                        <p class="df-offer__off">{{ $hero['hero_offer_off'] }}</p>
                    @endif
                    @if (filled($hero['hero_offer_label']))
                        <p class="df-offer__label">{{ $hero['hero_offer_label'] }}</p>
                    @endif
                    @if (filled($hero['hero_offer_button']))
                        <a href="{{ $hero['hero_offer_link'] }}"
                            class="df-btn df-btn--dark df-btn--shine df-offer__btn">{{ $hero['hero_offer_button'] }}</a>
                    @endif
                </aside>
            @endif

            @if ($slideCount > 1)
                <div class="df-hero__dots" role="group" aria-label="Hero pictures">
                    @foreach ($hero['slides'] as $index => $unused)
                        <button type="button" class="df-hero__dot @if ($index === 0) is-active @endif"
                            :class="slide === {{ $index }} && 'is-active'" @click="show({{ $index }})"
                            aria-label="Show picture {{ $index + 1 }}"></button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- One picture stays put; several fade from one to the next every 5s. --}}
        <div class="df-hero__figure">
            @foreach ($hero['slides'] as $index => $slide)
                <img src="{{ $slide }}" alt="" loading="{{ $index ? 'lazy' : 'eager' }}"
                    class="df-hero__img @if ($index === 0) is-active @endif"
                    @if ($slideCount > 1) :class="slide === {{ $index }} && 'is-active'" @endif>
            @endforeach
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
                    @foreach ($tiles as $i => $tile)
                        <a href="{{ $tile['url'] }}" class="df-cat df-cat--{{ $i + 1 }}"
                            aria-label="{{ $tile['label'] }}">
                            <img src="{{ $tile['image'] }}" alt="{{ $tile['label'] }}" loading="lazy">
                            <span class="df-cat__label">{{ $tile['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================
     STATS
     ============================================================ --}}
    @if ($stats)
        <section class="df-stats-wrap">
            <div class="df-wrap df-stats">
                @foreach ($stats as $stat)
                    <div class="df-stat">
                        <span class="df-stat__icon">
                            @switch($stat['icon'])
                                @case('box')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20 7.5 12 3 4 7.5v9L12 21l8-4.5v-9Z" />
                                        <path d="m4 7.5 8 4.5 8-4.5M12 12v9" />
                                    </svg>
                                @break

                                @case('users')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="9" cy="8" r="3.2" />
                                        <path d="M3 20a6 6 0 0 1 12 0" />
                                        <circle cx="17.5" cy="9" r="2.4" />
                                        <path d="M16 20a5 5 0 0 1 5-5" />
                                    </svg>
                                @break

                                @case('chart')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M5 20V12m7 8V5m7 15v-6" />
                                        <path d="M3 20h18" />
                                    </svg>
                                @break

                                @default
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="8" r="3.4" />
                                        <path d="M5 20a7 7 0 0 1 14 0" />
                                    </svg>
                            @endswitch
                        </span>
                        <p class="df-stat__label">{{ $stat['label'] }}</p>
                        <p class="df-stat__value">{{ $stat['value'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ============================================================
     BEST SELLERS
     ============================================================ --}}
    <section class="df-section df-paper" id="best-sellers" x-data="{ tab: @js(array_key_first($tabs)) }">
        <div class="df-wrap">
            <h2 class="df-heading">Best sellers</h2>
            <p class="df-sub">
                Established in 2020, we bring stylish and elegant ladies' fashion with a wide range of stitched and
                unstitched collections.
                Our focus is on quality, comfort, and trendy designs for every occasion.
                We create fashion that celebrates every woman's unique style.
            </p>

            <div class="df-tabs">
                @foreach ($tabs as $label => $items)
                    <button type="button" @click="tab = @js($label)" class="df-tab"
                        :class="tab === @js($label) && 'is-active'">{{ $label }}</button>
                @endforeach
            </div>

            @foreach ($tabs as $label => $items)
                <div class="df-grid" x-show="tab === @js($label)" x-cloak>
                    @foreach ($items as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
            @endforeach
        </div>
    </section>

    {{-- ============================================================
     BRANDS
     ============================================================ --}}
    @if ($brands->isNotEmpty())
        @php
            // The strip scrolls one whole group's width and snaps back, so the loop is
            // seamless only when both groups are identical. With few brands the group is
            // repeated until it is wide enough to fill the screen. Speed follows the
            // width, so two logos do not race past while twelve crawl.
            $perGroup = max(6, $brands->count());
            $marquee = collect()
                ->pad((int) ceil($perGroup / $brands->count()), null)
                ->flatMap(fn() => $brands)
                ->take($perGroup);
            $seconds = $perGroup * 4;
        @endphp

        <section class="df-brands" aria-label="Brands we carry">
            <div class="df-brands__viewport" style="--df-marquee: {{ $seconds }}s">
                @foreach ([false, true] as $duplicate)
                    <div class="df-brands__group" @if ($duplicate) aria-hidden="true" @endif>
                        @foreach ($marquee as $brand)
                            <span class="df-brand">
                                <img src="{{ $brand->logo_url }}" alt="{{ $duplicate ? '' : $brand->name }}"
                                    loading="lazy">
                            </span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ============================================================
     FACEBOOK COMMUNITY
     ============================================================ --}}
    <section class="df-section df-paper">
        <div class="df-wrap">
            <h2 class="df-heading">Facebook Community</h2>

            <div class="df-insta">
                @foreach ($gallery as $shot)
                    {{-- <a href="{{ route('shop.show', $shot) }}" class="df-insta__item"> --}}
                    <a href="https://www.facebook.com/share/1Bzz3ZCT4X/" class="df-insta__item" target="_blank">
                        <img src="{{ $shot->image_url }}" alt="{{ $shot->name }}" loading="lazy">
                        <span class="df-insta__icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M13.5 21v-7h2.4l.4-2.8h-2.8V9.4c0-.8.2-1.4 1.4-1.4h1.5V5.5c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8v2H8v2.8h2.5V21h3Z" />
                            </svg>
                        </span>
                    </a>
                @endforeach
            </div>

            <p class="df-insta__script">Check our Facebook...</p>
        </div>
    </section>

    {{-- ============================================================
     NEWSLETTER
     ============================================================ --}}
    <section class="df-news-wrap" id="newsletter">
        <div class="df-wrap">
            <div class="df-news">
                <h2 class="df-heading df-heading--sm">Stay Updated With Our Latest Styles</h2>
                <p class="df-sub">
                    Subscribe to our newsletter and be the first to know about new arrivals,<br>
                    exclusive offers, seasonal collections, and special discounts.
                </p>

                <form class="df-news__form" method="POST" action="{{ route('newsletter.store') }}">
                    @csrf
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="Your email address"
                        aria-label="Your email address" autocomplete="email" required>
                    <button type="submit" class="df-btn df-btn--olive df-btn--sm">Join Us</button>
                </form>

                @if ($errors->newsletter->has('email'))
                    <p class="df-news__msg df-news__msg--error">{{ $errors->newsletter->first('email') }}</p>
                @elseif(session('newsletter_status'))
                    <p class="df-news__msg df-news__msg--ok">{{ session('newsletter_status') }}</p>
                @endif

                <p class="df-news__note">Never miss out on new arrivals or special launches.</p>
            </div>
        </div>
    </section>

@endsection
