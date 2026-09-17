@extends('layouts.admin')

@php $editing = $purchase->exists; @endphp

@section('title', $editing ? 'Edit ' . $purchase->number : 'New purchase')
@section('heading', $editing ? 'Edit ' . $purchase->number : 'New purchase')

@section('content')
    <form method="POST" action="{{ $editing ? route('admin.purchases.update', $purchase) : route('admin.purchases.store') }}"
          x-data="{
              lines: @js($lines),
              query: '',
              results: [],
              searching: false,
              message: '',
              discount: Number(@js(old('discount', (float) $purchase->discount))) || 0,
              additional: Number(@js(old('additional_cost', (float) $purchase->additional_cost))) || 0,
              async search(fromEnter = false) {
                  const term = this.query.trim();
                  if (term.length < 2) { this.results = []; this.message = ''; return; }
                  this.searching = true;
                  try {
                      const response = await fetch(@js(route('admin.variants.search')) + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
                      if (! response.ok) throw new Error();
                      const data = await response.json();
                      this.results = data.results;
                      this.message = data.results.length ? '' : 'Nothing matches “' + term + '”.';
                      if (fromEnter && data.results.length && (data.exact || data.results.length === 1)) this.add(data.results[0]);
                  } catch (e) {
                      this.message = 'Search failed. Check your connection and try again.';
                  } finally {
                      this.searching = false;
                  }
              },
              add(result) {
                  const existing = this.lines.find(line => line.id === result.id);
                  if (existing) {
                      existing.quantity = Number(existing.quantity) + 1;
                  } else {
                      this.lines.push({ ...result, quantity: 1, unit_cost: result.cost ?? 0 });
                  }
                  this.results = []; this.query = ''; this.message = '';
              },
              remove(index) { this.lines.splice(index, 1); },
              lineTotal(line) { return (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0); },
              get subtotal() { return this.lines.reduce((sum, line) => sum + this.lineTotal(line), 0); },
              get total() { return this.subtotal - (Number(this.discount) || 0) + (Number(this.additional) || 0); },
              get units() { return this.lines.reduce((sum, line) => sum + (Number(line.quantity) || 0), 0); },
              money(value) { return @js(\App\Support\Money::symbol()) + ' ' + (Number(value) || 0).toFixed(2); },
          }">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <div class="min-w-0 space-y-6">
                <section class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Products</h2>
                    <p class="text-xs text-slate-500">Scan a barcode or search, then set the quantity and what you paid per unit.</p>

                    <div class="relative mt-4">
                        <label for="lookup" class="label">Add product</label>
                        <input id="lookup" type="search" x-model="query" @input.debounce.350ms="search()" @keydown.enter.prevent="search(true)"
                               placeholder="Scan barcode, or type a SKU or name" autocomplete="off" class="input">
                        <p class="mt-1 text-xs text-slate-500" x-show="searching" x-cloak>Searching…</p>
                        <p class="mt-1 text-xs text-rose-600" x-show="message" x-text="message" x-cloak></p>

                        <ul x-show="results.length" x-cloak class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                            <template x-for="result in results" :key="result.id">
                                <li>
                                    <button type="button" @click="add(result)" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-slate-800" x-text="result.full_name"></span>
                                            <span class="block font-mono text-xs text-slate-400" x-text="result.sku"></span>
                                        </span>
                                        <span class="text-xs text-slate-500">In stock: <span x-text="result.stock"></span></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">Product</th>
                                    <th class="w-28 px-3 py-2">Quantity</th>
                                    <th class="w-36 px-3 py-2">Purchase price</th>
                                    <th class="w-32 px-3 py-2 text-right">Line total</th>
                                    <th class="w-10 px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="(line, index) in lines" :key="line.id">
                                    <tr>
                                        <td class="px-3 py-2">
                                            <span class="block font-medium text-slate-800" x-text="line.full_name"></span>
                                            <span class="block font-mono text-xs text-slate-400" x-text="line.sku"></span>
                                            <input type="hidden" :name="'items[' + index + '][variant_id]'" :value="line.id">
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="number" min="1" max="100000" step="1" required class="input"
                                                   :name="'items[' + index + '][quantity]'" x-model="line.quantity">
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="number" min="0" max="10000000" step="0.01" required class="input"
                                                   :name="'items[' + index + '][unit_cost]'" x-model="line.unit_cost">
                                        </td>
                                        <td class="px-3 py-2 text-right font-semibold text-slate-900" x-text="money(lineTotal(line))"></td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" @click="remove(index)" class="text-slate-400 hover:text-rose-600" aria-label="Remove">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="! lines.length">
                                    <td colspan="5" class="px-3 py-8 text-center text-slate-500">No products yet. Search above to add the first one.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    @error('items') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                    @error('items.*.quantity') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                    @error('items.*.unit_cost') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </section>

                <section class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Costs</h2>
                    <p class="text-xs text-slate-500">Transport and labour are shared across the lines by value, so each unit gets its real cost.</p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="discount" class="label">Discount from the supplier</label>
                            <input id="discount" name="discount" type="number" min="0" step="0.01" x-model="discount" class="input">
                            @error('discount') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="additional_cost" class="label">Additional cost (transport, labour)</label>
                            <input id="additional_cost" name="additional_cost" type="number" min="0" step="0.01" x-model="additional" class="input">
                            @error('additional_cost') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <dl class="mt-5 space-y-2 border-t border-slate-200 pt-4 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Goods (<span x-text="units"></span> units)</dt><dd class="text-slate-900" x-text="money(subtotal)"></dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="text-emerald-700" x-text="'− ' + money(discount)"></dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Additional cost</dt><dd class="text-slate-900" x-text="'+ ' + money(additional)"></dd></div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-bold"><dt>Total</dt><dd :class="total < 0 ? 'text-rose-600' : 'text-slate-900'" x-text="money(total)"></dd></div>
                    </dl>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="card space-y-4 p-6">
                    <div>
                        <label for="supplier_id" class="label">Supplier</label>
                        <select id="supplier_id" name="supplier_id" required class="input">
                            <option value="">Choose a supplier</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', $purchase->supplier_id) === $supplier->id)>
                                    {{ $supplier->name }}{{ $supplier->company ? ' — ' . $supplier->company : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('supplier_id') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-slate-400">
                            Missing? <a href="{{ route('admin.suppliers.create') }}" class="text-brand-600 hover:underline">Add a supplier</a>.
                        </p>
                    </div>

                    <div>
                        <label for="purchase_date" class="label">Purchase date</label>
                        <input id="purchase_date" name="purchase_date" type="date" required max="{{ now()->toDateString() }}"
                               value="{{ old('purchase_date', $purchase->purchase_date?->format('Y-m-d') ?? now()->toDateString()) }}" class="input">
                        @error('purchase_date') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="invoice_number" class="label">Supplier invoice no. <span class="text-slate-400">(optional)</span></label>
                        <input id="invoice_number" name="invoice_number" type="text" maxlength="100"
                               value="{{ old('invoice_number', $purchase->invoice_number) }}" class="input font-mono">
                        @error('invoice_number') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="status" class="label">Stage</label>
                        <select id="status" name="status" class="input">
                            <option value="draft" @selected(old('status', $purchase->status) === 'draft')>Draft — still being written</option>
                            <option value="ordered" @selected(old('status', $purchase->status) === 'ordered')>Ordered — placed with the supplier</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-400">Nothing moves until you receive the goods.</p>
                    </div>

                    <div>
                        <label for="note" class="label">Note <span class="text-slate-400">(optional)</span></label>
                        <textarea id="note" name="note" rows="3" maxlength="500" class="input">{{ old('note', $purchase->note) }}</textarea>
                        @error('note') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </section>

                <div class="flex gap-2">
                    <button type="submit" class="btn-primary flex-1" :disabled="! lines.length">{{ $editing ? 'Save changes' : 'Save purchase' }}</button>
                    <a href="{{ $editing ? route('admin.purchases.show', $purchase) : route('admin.purchases.index') }}" class="btn-secondary">Cancel</a>
                </div>
            </aside>
        </div>
    </form>
@endsection
