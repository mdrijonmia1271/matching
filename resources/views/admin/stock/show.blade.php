@extends('layouts.admin')

@section('title', 'Stock movement #' . $movement->id)
@section('heading', 'Stock movement #' . $movement->id)

@section('content')
    @php
        $in = $movement->type === 'in';
        $value = $movement->unit_cost !== null ? (float) $movement->unit_cost * $movement->quantity : null;
    @endphp

    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('admin.stock.index') }}" class="text-sm text-slate-500 hover:text-brand-600">&larr; Back to stock history</a>

    <div class="mt-4 grid max-w-5xl gap-6 lg:grid-cols-[1fr_320px]">
        <section class="card p-6">
            <div class="flex flex-wrap items-center gap-3">
                <span class="rounded-full px-3 py-1 text-sm font-bold {{ $in ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                    {{ $in ? '+' : '−' }}{{ $movement->quantity }}
                </span>
                <h2 class="text-lg font-bold text-slate-900">{{ $movement->reason_label }}</h2>
            </div>

            <dl class="mt-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-500">Product</dt>
                    <dd class="font-medium text-slate-900">
                        {{ $movement->product?->name ?? 'Deleted product' }}
                        @if($movement->product?->trashed())<span class="text-xs text-slate-400">(archived)</span>@endif
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Variant</dt>
                    <dd class="font-medium text-slate-900">{{ $movement->variant?->label ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">SKU</dt>
                    <dd class="font-mono text-slate-900">{{ $movement->variant?->sku ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Barcode</dt>
                    <dd class="font-mono text-slate-900">{{ $movement->variant?->barcode ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Stock before → after</dt>
                    <dd class="font-medium text-slate-900">{{ $movement->stock_before }} &rarr; {{ $movement->stock_after }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Unit cost</dt>
                    <dd class="font-medium text-slate-900">
                        {{ $movement->unit_cost !== null ? \App\Support\Money::format($movement->unit_cost) : 'Not recorded' }}
                        @if($value !== null)<span class="text-xs text-slate-500">(total @money($value))</span>@endif
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-slate-500">Note</dt>
                    <dd class="whitespace-pre-line text-slate-900">{{ $movement->note ?: '—' }}</dd>
                </div>
            </dl>
        </section>

        <aside class="card h-fit p-6 text-sm">
            <dl class="space-y-4">
                <div>
                    <dt class="text-slate-500">Date &amp; time</dt>
                    <dd class="font-medium text-slate-900">{{ $movement->created_at?->format('d M Y, h:i A') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Recorded by</dt>
                    <dd class="font-medium text-slate-900">{{ $movement->user?->name ?? ($movement->order ? 'Customer (online order)' : 'System') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Reference</dt>
                    <dd class="font-medium text-slate-900">
                        @if($movement->order)
                            <a href="{{ route('admin.orders.show', $movement->order) }}" class="text-brand-600 hover:underline">Order {{ $movement->order->order_number }}</a>
                        @elseif($movement->reference_type)
                            {{ class_basename($movement->reference_type) }} #{{ $movement->reference_id }}
                        @else
                            Manual entry
                        @endif
                    </dd>
                </div>
            </dl>

            @if($movement->variant_id)
                <div class="mt-6 space-y-2 border-t border-slate-200 pt-4">
                    <a href="{{ route('admin.stock.index', ['variant' => $movement->variant_id]) }}" class="block text-brand-600 hover:underline">All movements for this item</a>
                    @can('inventory.adjust')
                        <a href="{{ route('admin.stock.create', ['variant' => $movement->variant_id]) }}" class="block text-brand-600 hover:underline">Adjust this item's stock</a>
                    @endcan
                </div>
            @endif
        </aside>
    </div>
@endsection
