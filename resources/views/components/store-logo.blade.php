@props(['variant' => 'shop'])

{{--
    The store logo, driven by Admin → Settings.

    Upload a logo there and it appears here; clear it and the store name is
    shown as a wordmark instead. One definition, used by the storefront header,
    the storefront footer and the admin sidebar, so changing the setting changes
    every one of them.
--}}
@php
    $logo = \App\Support\Settings::get('store_logo');
    $name = \App\Support\Settings::get('store_name');
    $src = $logo ? asset('storage/' . $logo) : null;
@endphp

@if($variant === 'admin')
    {{-- The admin sidebar is labelled in words only, no logo. --}}
    <span class="truncate text-lg font-bold text-white">Admin Panel</span>
@else
    {{-- On the storefront the uploaded logo stands on its own: the store name
         lives in the image itself, so repeating it as text would say it twice.
         With no logo uploaded the name becomes the wordmark instead. The alt
         text keeps the brand readable for screen readers either way. --}}
    {{-- The animated logo video takes the image's place on the storefront.
         Muted + playsinline so phones autoplay it; the poster shows until it
         starts. --}}
    @if(file_exists(public_path('videos/logo.mp4')))
        <video class="df-logo__img df-logo__video" autoplay muted loop playsinline preload="auto"
               poster="{{ asset('videos/logo-poster.jpg') }}?v={{ filemtime(public_path('videos/logo-poster.jpg')) }}" aria-label="{{ $name }}">
            <source src="{{ asset('videos/logo.mp4') }}?v={{ filemtime(public_path('videos/logo.mp4')) }}" type="video/mp4">
        </video>
    @elseif($src)
        <img src="{{ $src }}" alt="{{ $name }}" class="df-logo__img">
    @else
        <span class="df-logo__text">{{ $name }}</span>
    @endif
@endif
