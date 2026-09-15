@extends('layouts.admin')

@section('title', 'Stock entry')
@section('heading', 'Stock entry')

@section('content')
    <form method="POST" action="{{ route('admin.stock.store') }}" class="card max-w-2xl p-6"
          x-data="{
              type: @js($type),
              variant: @js($selected),
              query: '',
              results: [],
              searching: false,
              message: '',
              qty: @js((int) old('quantity', 1)),
              allowNegative: @js($allowNegative),
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
                      if (fromEnter && data.results.length && (data.exact || data.results.length === 1)) this.pick(data.results[0]);
                  } catch (e) {
                      this.message = 'Search failed. Check your connection and try again.';
                  } finally {
                      this.searching = false;
                  }
              },
              pick(result) { this.variant = result; this.results = []; this.query = ''; this.message = ''; },
              get after() {
                  if (! this.variant) return null;
                  const qty = Number(this.qty) || 0;
                  return this.type === 'in' ? this.variant.stock + qty : this.variant.stock - qty;
              },
              get blocked() { return this.after !== null && this.after < 0 && ! this.allowNegative; },
          }">
        @csrf

        <div class="grid grid-cols-2 gap-3">
            <label class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border-2 px-4 py-3 text-sm font-semibold transition"
                   :class="type === 'in' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                <input type="radio" name="type" value="in" x-model="type" class="sr-only">
                + Stock in
            </label>
            <label class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border-2 px-4 py-3 text-sm font-semibold transition"
                   :class="type === 'out' ? 'border-rose-500 bg-rose-50 text-rose-700' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                <input type="radio" name="type" value="out" x-model="type" class="sr-only">
                − Stock out
            </label>
        </div>

        <div class="mt-5">
            <label for="lookup" class="label">Product</label>
            <input type="hidden" name="variant_id" :value="variant ? variant.id : ''">

            <div x-show="variant" class="flex items-center gap-3 rounded-lg border border-brand-200 bg-brand-50/50 p-3" @if(! $selected) x-cloak @endif>
                <img :src="variant?.image" alt="" class="h-12 w-12 rounded object-cover" @if($selected) src="{{ $selected['image'] }}" @endif>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium text-slate-900" x-text="variant?.full_name">{{ $selected['full_name'] ?? '' }}</p>
                    <p class="text-xs text-slate-500">
                        <span class="font-mono" x-text="variant?.sku">{{ $selected['sku'] ?? '' }}</span>
                        <span x-show="variant?.barcode"> &middot; <span class="font-mono" x-text="variant?.barcode">{{ $selected['barcode'] ?? '' }}</span></span>
                    </p>
                </div>
                <button type="button" @click="variant = null; $nextTick(() => $refs.lookup.focus())" class="text-xs text-slate-500 hover:underline">Change</button>
            </div>

            <div x-show="! variant" class="relative" @if($selected) x-cloak @endif>
                <input id="lookup" x-ref="lookup" type="search" x-model="query" autocomplete="off" @if(! $selected) autofocus @endif
                       @input.debounce.300ms="search()" @keydown.enter.prevent="search(true)"
                       class="input" placeholder="Scan a barcode or type a SKU / product name">
                <p x-show="searching" class="mt-1 text-xs text-slate-500">Searching…</p>
                <p x-show="message" x-text="message" class="mt-1 text-xs text-rose-600"></p>

                <ul x-show="results.length" class="absolute z-10 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                    <template x-for="result in results" :key="result.id">
                        <li>
                            <button type="button" @click="pick(result)" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-slate-50">
                                <img :src="result.image" alt="" class="h-9 w-9 rounded object-cover">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-slate-900" x-text="result.full_name"></span>
                                    <span class="block font-mono text-xs text-slate-400" x-text="result.sku"></span>
                                </span>
                                <span class="text-xs font-semibold text-slate-600" x-text="result.stock + ' in stock'"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="quantity" class="label">Quantity</label>
                <input id="quantity" name="quantity" type="number" min="1" required x-model.number="qty" class="input">
            </div>

            <div>
                <label class="label" for="reason_in">Reason</label>
                <select id="reason_in" name="reason" class="input" x-show="type === 'in'" :disabled="type !== 'in'">
                    @foreach(\App\Models\StockMovement::IN_REASONS as $key => $label)
                        <option value="{{ $key }}" @selected(old('type') === 'in' && old('reason') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <select id="reason_out" name="reason" class="input" x-show="type === 'out'" x-cloak :disabled="type !== 'out'">
                    @foreach(\App\Models\StockMovement::OUT_REASONS as $key => $label)
                        <option value="{{ $key }}" @selected(old('type') === 'out' && old('reason') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-2">
                <label for="note" class="label">Note <span class="text-slate-400">(optional)</span></label>
                <textarea id="note" name="note" rows="2" maxlength="500" class="input"
                          placeholder="Supplier name, invoice no., who took it…">{{ old('note') }}</textarea>
            </div>
        </div>

        <div x-show="variant" x-cloak
             class="mt-5 flex items-center justify-between rounded-lg px-4 py-3 text-sm"
             :class="after < 0 ? 'bg-rose-50 text-rose-800' : 'bg-slate-50 text-slate-700'">
            <span>Current stock: <strong x-text="variant?.stock"></strong></span>
            <span>After this entry: <strong x-text="after"></strong></span>
        </div>
        <p x-show="blocked" x-cloak class="mt-2 text-sm text-rose-600">
            You cannot take out more than is in stock.
        </p>

        <div class="mt-6 flex flex-wrap gap-3">
            <button type="submit" class="btn-primary" :disabled="! variant || blocked">Save entry</button>
            <button type="submit" name="another" value="1" class="btn-secondary" :disabled="! variant || blocked">Save &amp; add another</button>
            <a href="{{ route('admin.stock.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
@endsection
