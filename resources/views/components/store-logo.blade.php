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
    @if($src)
        <img src="{{ $src }}" alt="{{ $name }}" class="h-8 w-8 shrink-0 rounded-lg bg-white/10 object-contain p-0.5">
    @else
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">
            {{ strtoupper(substr($name, 0, 1)) }}
        </span>
    @endif
    <span class="truncate font-bold text-white">{{ $name }} Admin</span>
@else
    {{-- On the storefront the uploaded logo stands on its own: the store name
         lives in the image itself, so repeating it as text would say it twice.
         With no logo uploaded the name becomes the wordmark instead. The alt
         text keeps the brand readable for screen readers either way. --}}
    @if($src)
        <img src="{{ $src }}" alt="{{ $name }}" class="df-logo__img">
    @else
        <span class="df-logo__text">{{ $name }}</span>
    @endif
@endif
