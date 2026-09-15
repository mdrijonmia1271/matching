@extends('layouts.app')

@section('title', 'Wishlist')

@section('content')
    <div class="mx-auto max-w-page px-4 py-10 sm:px-6">
        <h1 class="text-2xl font-bold text-slate-900">Wishlist</h1>

        @if($items->isEmpty())
            <div class="card mt-8 grid place-items-center gap-3 p-16 text-center">
                <p class="text-lg font-semibold text-slate-900">Nothing saved yet</p>
                <p class="text-sm text-slate-500">Tap the heart on any product to keep it here.</p>
                <a href="{{ route('shop.index') }}" class="btn-primary mt-2">Browse products</a>
            </div>
        @else
            <div class="mt-8 grid grid-cols-2 gap-5 lg:grid-cols-4">
                @foreach($items as $item)
                    @if($item->product)
                        <x-product-card :product="$item->product" />
                    @endif
                @endforeach
            </div>
        @endif
    </div>
@endsection
