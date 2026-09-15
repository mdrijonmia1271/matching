@extends('layouts.admin')

@section('title', 'Barcode labels')
@section('heading', 'Barcode labels')

@section('content')
    <div class="grid gap-6 xl:grid-cols-[1fr_340px]"
         x-data="{
             rows: @js($preselected),
             query: '',
             results: [],
             searching: false,
             message: '',
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
                     // A scanner types the code and presses Enter: add the exact match straight away.
                     if (fromEnter && data.results.length && (data.exact || data.results.length === 1)) this.add(data.results[0]);
                 } catch (e) {
                     this.message = 'Search failed. Check your connection and try again.';
                 } finally {
                     this.searching = false;
                 }
             },
             add(result) {
                 const existing = this.rows.find(row => row.id === result.id);
                 if (existing) existing.quantity = Number(existing.quantity) + 1;
                 else this.rows.push({ ...result, quantity: 1 });
                 this.results = []; this.query = ''; this.message = '';
             },
             remove(index) { this.rows.splice(index, 1); },
             useStock() { this.rows.forEach(row => row.quantity = Math.max(1, row.stock)); },
             get total() { return this.rows.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0); },
             get missing() { return this.rows.filter(row => ! row.barcode); },
         }">

        <div class="card min-w-0">
            <div class="border-b border-slate-200 p-4">
                <label for="lookup" class="label">Add products</label>
                <div class="relative">
                    <input id="lookup" type="search" x-model="query" autocomplete="off" autofocus
                           @input.debounce.300ms="search()" @keydown.enter.prevent="search(true)"
                           class="input" placeholder="Scan a barcode or type a SKU / product name">
                    <p x-show="searching" x-cloak class="mt-1 text-xs text-slate-500">Searching…</p>
                    <p x-show="message" x-cloak x-text="message" class="mt-1 text-xs text-rose-600"></p>

                    <ul x-show="results.length" x-cloak class="absolute z-10 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                        <template x-for="result in results" :key="result.id">
                            <li>
                                <button type="button" @click="add(result)" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-slate-50">
                                    <img :src="result.image" alt="" class="h-9 w-9 rounded object-cover">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm text-slate-900" x-text="result.full_name"></span>
                                        <span class="block font-mono text-xs text-slate-400" x-text="result.sku"></span>
                                    </span>
                                    <span class="text-xs" :class="result.barcode ? 'text-slate-500' : 'font-semibold text-amber-600'" x-text="result.barcode ? result.stock + ' in stock' : 'No barcode'"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3">Barcode</th>
                            <th class="px-4 py-3 text-center">Stock</th>
                            <th class="px-4 py-3 text-center">Labels</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(row, index) in rows" :key="row.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-900" x-text="row.full_name"></p>
                                    <p class="font-mono text-xs text-slate-400" x-text="row.sku"></p>
                                </td>
                                <td class="px-4 py-3">
                                    <span x-show="row.barcode" class="font-mono text-xs text-slate-700" x-text="row.barcode"></span>
                                    <span x-show="! row.barcode" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">No barcode</span>
                                </td>
                                <td class="px-4 py-3 text-center text-slate-600" x-text="row.stock"></td>
                                <td class="px-4 py-3 text-center">
                                    <input type="number" min="1" max="500" x-model.number="row.quantity" class="input w-20 px-2 py-1.5 text-center" aria-label="Number of labels">
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" @click="remove(index)" class="text-xs text-rose-600 hover:underline">Remove</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="! rows.length">
                            <td colspan="5" class="px-4 py-12 text-center text-slate-500">
                                Scan or search for products above. Each scan adds one label.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div x-show="rows.length" x-cloak class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 text-sm">
                <button type="button" @click="useStock()" class="text-brand-600 hover:underline">Set labels = stock on hand</button>
                <button type="button" @click="rows = []" class="text-slate-500 hover:underline">Clear list</button>
            </div>
        </div>

        <aside class="space-y-6">
            <form method="POST" action="{{ route('admin.barcodes.print') }}" target="_blank" class="card space-y-4 p-6">
                @csrf
                <h2 class="text-base font-bold text-slate-900">Print</h2>

                <template x-for="(row, index) in rows" :key="row.id">
                    <span>
                        <input type="hidden" :name="`items[${index}][variant_id]`" :value="row.id">
                        <input type="hidden" :name="`items[${index}][quantity]`" :value="row.quantity">
                    </span>
                </template>

                <div>
                    <label for="size" class="label">Label size</label>
                    <select id="size" name="size" class="input">
                        @foreach($sizes as $key => $size)
                            <option value="{{ $key }}" @selected(old('size', 'roll_38x25') === $key)>{{ $size['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="show_price" value="0">
                    <input type="checkbox" name="show_price" value="1" @checked(old('show_price', true)) class="rounded text-brand-600 focus:ring-brand-500">
                    Show selling price
                </label>

                <p class="text-sm text-slate-600"><span class="font-semibold text-slate-900" x-text="total"></span> label(s)</p>

                <p x-show="missing.length" x-cloak class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    <span x-text="missing.length"></span> item(s) have no barcode. Generate barcodes before printing.
                </p>

                <button type="submit" class="btn-primary w-full" :disabled="! rows.length || missing.length > 0 || total > {{ \App\Http\Controllers\Admin\BarcodeLabelController::MAX_LABELS }}">
                    Open print preview
                </button>
                <p class="text-xs text-slate-500">Opens in a new tab. Set the printer to the same label size and 100% scale.</p>
            </form>

            @can('products.edit')
                <form method="POST" action="{{ route('admin.barcodes.generate') }}" x-show="missing.length" x-cloak class="card space-y-3 p-6">
                    @csrf
                    <input type="hidden" name="scope" value="selected">
                    <template x-for="row in missing" :key="row.id">
                        <input type="hidden" name="variant_ids[]" :value="row.id">
                    </template>
                    <h2 class="text-base font-bold text-slate-900">Missing barcodes</h2>
                    <p class="text-sm text-slate-600">Create unique in-store EAN-13 barcodes for the <span x-text="missing.length"></span> item(s) in the list that have none.</p>
                    <button type="submit" class="btn-secondary w-full">Generate for these items</button>
                </form>

                @if($missingCount)
                    <form method="POST" action="{{ route('admin.barcodes.generate') }}" class="card space-y-3 p-6"
                          onsubmit="return confirm('Generate barcodes for all {{ $missingCount }} active items that have none?')">
                        @csrf
                        <input type="hidden" name="scope" value="all_missing">
                        <p class="text-sm text-slate-600"><span class="font-semibold text-slate-900">{{ $missingCount }}</span> active item(s) in the catalogue have no barcode.</p>
                        <button type="submit" class="btn-secondary w-full">Generate all missing barcodes</button>
                    </form>
                @endif
            @endcan
        </aside>
    </div>
@endsection
