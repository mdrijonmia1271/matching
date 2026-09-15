@extends('layouts.admin')

@section('title', 'Stock count')
@section('heading', 'Stock count')

@section('content')
    <form method="GET" class="card flex flex-wrap items-center gap-2 p-4">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Product, SKU or barcode" class="input w-56">

        <select name="category" class="input w-44">
            <option value="">All categories</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
                @foreach($category->children as $child)
                    <option value="{{ $child->id }}" @selected(request('category') == $child->id)>&nbsp;&nbsp;&rsaquo; {{ $child->name }}</option>
                @endforeach
            @endforeach
        </select>

        <select name="brand" class="input w-36">
            <option value="">All brands</option>
            @foreach($brands as $brand)
                <option value="{{ $brand->id }}" @selected(request('brand') == $brand->id)>{{ $brand->name }}</option>
            @endforeach
        </select>

        <select name="status" class="input w-36">
            <option value="">Any status</option>
            @foreach($statuses as $key => $label)
                <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn-secondary">Load items</button>
        <a href="{{ route('admin.inventory.count', ['all' => 1]) }}" class="text-sm text-slate-500 hover:underline">Load everything</a>
    </form>

    @if(! $filtered)
        <div class="card mt-6 p-10 text-center text-slate-500">
            <p class="font-medium text-slate-700">Choose what you are counting.</p>
            <p class="mt-1 text-sm">Pick a category, brand or search above — for example one shelf or rack at a time.</p>
        </div>
    @elseif($variants->isEmpty())
        <div class="card mt-6 p-10 text-center text-slate-500">No items match these filters.</div>
    @else
        @php
            // Counts typed before a validation error come back instead of being lost.
            $previous = collect(old('counts', []))->mapWithKeys(fn ($row) => [(int) ($row['variant_id'] ?? 0) => $row['counted'] ?? '']);
        @endphp
        <form method="POST" action="{{ route('admin.inventory.count.store') }}" class="mt-6"
              x-data="{
                  scan: '',
                  flash: '',
                  codes: @js($variants->map(fn ($v) => ['id' => $v->id, 'sku' => mb_strtolower($v->sku), 'barcode' => $v->barcode])->values()),
                  expected: @js((object) $variants->pluck('stock', 'id')->all()),
                  counted: @js((object) $variants->mapWithKeys(fn ($v) => [$v->id => $previous[$v->id] ?? ''])->all()),
                  hit() {
                      const code = this.scan.trim();
                      this.scan = '';
                      if (! code) return;
                      const row = this.codes.find(r => r.barcode === code || r.sku === code.toLowerCase());
                      if (! row) { this.flash = code + ' is not in this list.'; return; }
                      this.counted[row.id] = (Number(this.counted[row.id]) || 0) + 1;
                      this.flash = '';
                      document.getElementById('row-' + row.id)?.scrollIntoView({ block: 'center' });
                  },
                  difference(id) {
                      const value = this.counted[id];
                      return value === '' || value === null || value === undefined ? null : Number(value) - Number(this.expected[id]);
                  },
                  get changes() { return Object.keys(this.expected).filter(id => { const d = this.difference(id); return d !== null && d !== 0; }).length; },
              }">
            @csrf

            <div class="card">
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
                    <div class="w-72">
                        <input type="search" x-model="scan" @keydown.enter.prevent="hit()" class="input" placeholder="Scan items to count them (+1 per scan)" autofocus>
                        <p x-show="flash" x-cloak x-text="flash" class="mt-1 text-xs text-rose-600"></p>
                    </div>
                    <p class="text-sm text-slate-500">
                        {{ $variants->count() }} item(s). Leave the count blank for anything you did not count.
                        @if($truncated)<span class="text-amber-600">Only the first {{ \App\Http\Controllers\Admin\InventoryController::COUNT_LIMIT }} are shown — narrow the filters.</span>@endif
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Product</th>
                                <th class="px-4 py-3">SKU / barcode</th>
                                <th class="px-4 py-3 text-center">System stock</th>
                                <th class="px-4 py-3 text-center">Counted</th>
                                <th class="px-4 py-3 text-center">Difference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($variants as $i => $variant)
                                <tr id="row-{{ $variant->id }}" class="hover:bg-slate-50"
                                    :class="difference({{ $variant->id }}) ? 'bg-amber-50/60' : ''">
                                    <td class="px-4 py-2.5">
                                        <input type="hidden" name="counts[{{ $i }}][variant_id]" value="{{ $variant->id }}">
                                        <input type="hidden" name="counts[{{ $i }}][expected]" value="{{ $variant->stock }}">
                                        <p class="font-medium text-slate-900">{{ $variant->product->name }}</p>
                                        @if($variant->product->has_variants)<p class="text-xs text-slate-500">{{ $variant->label }}</p>@endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <p class="font-mono text-xs text-slate-700">{{ $variant->sku }}</p>
                                        <p class="font-mono text-xs text-slate-400">{{ $variant->barcode }}</p>
                                    </td>
                                    <td class="px-4 py-2.5 text-center font-semibold text-slate-700">{{ $variant->stock }}</td>
                                    <td class="px-4 py-2.5 text-center">
                                        <input type="number" min="0" name="counts[{{ $i }}][counted]" x-model="counted[{{ $variant->id }}]"
                                               class="input mx-auto w-24 px-2 py-1.5 text-center" aria-label="Counted quantity">
                                    </td>
                                    <td class="px-4 py-2.5 text-center font-bold"
                                        :class="difference({{ $variant->id }}) > 0 ? 'text-emerald-600' : (difference({{ $variant->id }}) < 0 ? 'text-rose-600' : 'text-slate-400')"
                                        x-text="difference({{ $variant->id }}) === null ? '—' : (difference({{ $variant->id }}) > 0 ? '+' : '') + difference({{ $variant->id }})">—</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-end gap-3 border-t border-slate-200 p-4">
                    <div class="min-w-64 flex-1">
                        <label for="note" class="label">Note <span class="text-slate-400">(optional)</span></label>
                        <input id="note" name="note" type="text" maxlength="300" value="{{ old('note') }}" class="input" placeholder="e.g. Monthly count, rack B">
                    </div>
                    <button type="submit" class="btn-primary" :disabled="changes === 0"
                            onclick="return confirm('Record the differences as stock adjustments?')">
                        Record <span x-text="changes"></span> adjustment(s)
                    </button>
                </div>
            </div>

            <p class="mt-3 text-xs text-slate-500">
                Only the difference between what you counted and the system stock shown here is recorded, so sales made while you were counting are kept.
            </p>
        </form>
    @endif
@endsection
