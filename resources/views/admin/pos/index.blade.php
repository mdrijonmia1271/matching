@extends('layouts.admin')

@section('title', 'Counter')
@section('heading', 'Counter (POS)')

@section('content')
    <form method="POST" action="{{ route('admin.pos.store') }}"
          x-data="{
              lines: [],
              query: '',
              results: [],
              searching: false,
              message: '',
              discount: 0,
              note: '',
              customer: null,
              customerQuery: '',
              customerResults: [],
              customerSearching: false,
              newCustomer: false,
              newName: '',
              newPhone: '',
              payments: [{ method: @js(array_key_first($methods)), account_id: String(@js($methodAccounts[array_key_first($methods)] ?? '')), amount: '' }],
              defaults: @js($methodAccounts),
              cashGiven: '',
              submitting: false,

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
                  if (! result.sellable) { this.message = result.full_name + ' is not on sale.'; return; }
                  const existing = this.lines.find(line => line.id === result.id);
                  if (existing) existing.quantity = Number(existing.quantity) + 1;
                  else this.lines.push({ ...result, quantity: 1 });
                  this.results = []; this.query = ''; this.message = '';
                  this.$nextTick(() => this.$refs.lookup.focus());
              },
              remove(index) { this.lines.splice(index, 1); },

              async findCustomer() {
                  const term = this.customerQuery.trim();
                  if (term.length < 2) { this.customerResults = []; return; }
                  this.customerSearching = true;
                  try {
                      const response = await fetch(@js(route('admin.pos.customers')) + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
                      const data = await response.json();
                      this.customerResults = data.results;
                  } catch (e) {
                      this.customerResults = [];
                  } finally {
                      this.customerSearching = false;
                  }
              },
              pickCustomer(result) { this.customer = result; this.customerResults = []; this.customerQuery = ''; this.newCustomer = false; },
              clearCustomer() { this.customer = null; this.newCustomer = false; this.newName = ''; this.newPhone = ''; },

              addPayment() {
                  if (this.payments.length >= 5) return;
                  const method = @js(array_key_first($methods));
                  this.payments.push({ method, account_id: String(this.defaults[method] ?? ''), amount: '' });
              },
              removePayment(index) { this.payments.splice(index, 1); },
              payFull(index) { this.payments[index].amount = Math.max(0, this.remaining + (Number(this.payments[index].amount) || 0)).toFixed(2); },

              lineTotal(line) { return (Number(line.quantity) || 0) * (Number(line.price) || 0); },
              get subtotal() { return this.lines.reduce((sum, line) => sum + this.lineTotal(line), 0); },
              get total() { return Math.max(0, this.subtotal - (Number(this.discount) || 0)); },
              get units() { return this.lines.reduce((sum, line) => sum + (Number(line.quantity) || 0), 0); },
              get taken() { return this.payments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0); },
              get remaining() { return Math.max(0, this.total - this.taken); },
              get overpaid() { return this.taken > this.total; },
              get change() { return Math.max(0, (Number(this.cashGiven) || 0) - this.taken); },
              money(value) { return @js(\App\Support\Money::symbol()) + ' ' + (Number(value) || 0).toFixed(2); },
          }"
          @submit="submitting = true">
        @csrf

        <div class="grid gap-6 lg:grid-cols-[1fr_360px]">
            <div class="min-w-0 space-y-6">
                <section class="card p-6">
                    <div class="relative">
                        <label for="lookup" class="label">Scan or search</label>
                        <input id="lookup" x-ref="lookup" type="search" x-model="query" autofocus autocomplete="off"
                               @input.debounce.300ms="search()" @keydown.enter.prevent="search(true)"
                               placeholder="Scan a barcode, or type a SKU or product name" class="input text-base">
                        <p class="mt-1 text-xs text-slate-500" x-show="searching" x-cloak>Searching…</p>
                        <p class="mt-1 text-xs text-rose-600" x-show="message" x-text="message" x-cloak></p>

                        <ul x-show="results.length" x-cloak class="absolute z-20 mt-1 max-h-80 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                            <template x-for="result in results" :key="result.id">
                                <li>
                                    <button type="button" @click="add(result)" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-slate-800" x-text="result.full_name"></span>
                                            <span class="block font-mono text-xs text-slate-400" x-text="result.sku"></span>
                                        </span>
                                        <span class="text-xs" :class="result.stock > 0 ? 'text-slate-500' : 'text-rose-600'">Stock: <span x-text="result.stock"></span></span>
                                        <span class="w-24 text-right font-semibold text-slate-900" x-text="money(result.price)"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div class="mt-5 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">Product</th>
                                    <th class="w-28 px-3 py-2">Qty</th>
                                    <th class="w-28 px-3 py-2 text-right">Price</th>
                                    <th class="w-32 px-3 py-2 text-right">Total</th>
                                    <th class="w-10 px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="(line, index) in lines" :key="line.id">
                                    <tr>
                                        <td class="px-3 py-2">
                                            <span class="block font-medium text-slate-800" x-text="line.full_name"></span>
                                            <span class="block font-mono text-xs text-slate-400" x-text="line.sku"></span>
                                            <span class="block text-xs" :class="line.quantity > line.stock ? 'text-rose-600' : 'text-slate-400'"
                                                  x-text="'In stock: ' + line.stock"></span>
                                            <input type="hidden" :name="'items[' + index + '][variant_id]'" :value="line.id">
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="number" min="1" max="10000" step="1" required class="input"
                                                   :name="'items[' + index + '][quantity]'" x-model="line.quantity">
                                        </td>
                                        <td class="px-3 py-2 text-right text-slate-700" x-text="money(line.price)"></td>
                                        <td class="px-3 py-2 text-right font-semibold text-slate-900" x-text="money(lineTotal(line))"></td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" @click="remove(index)" class="text-slate-400 hover:text-rose-600" aria-label="Remove">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="! lines.length">
                                    <td colspan="5" class="px-3 py-12 text-center text-slate-500">Scan the first item to start a sale.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    @error('items') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                    @error('items.*.quantity') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </section>

                <section class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Customer</h2>
                    <p class="text-xs text-slate-500">Leave empty for a walk-in sale. A named customer can owe the rest.</p>

                    <div x-show="customer" x-cloak class="mt-4 flex items-center gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-900" x-text="customer?.name"></p>
                            <p class="text-xs text-slate-500">
                                <span x-text="customer?.phone || 'No phone'"></span> ·
                                <span x-text="customer?.group"></span>
                                <template x-if="customer?.due > 0">
                                    <span class="font-semibold text-rose-600"> · owes <span x-text="money(customer.due)"></span></span>
                                </template>
                            </p>
                            <input type="hidden" name="customer_id" :value="customer?.id">
                        </div>
                        <button type="button" @click="clearCustomer()" class="btn-secondary">Change</button>
                    </div>

                    <div x-show="! customer && ! newCustomer" x-cloak class="relative mt-4">
                        <input type="search" x-model="customerQuery" @input.debounce.300ms="findCustomer()" autocomplete="off"
                               placeholder="Search by name or phone" class="input">
                        <p class="mt-1 text-xs text-slate-500" x-show="customerSearching" x-cloak>Searching…</p>

                        <ul x-show="customerResults.length" x-cloak class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                            <template x-for="result in customerResults" :key="result.id">
                                <li>
                                    <button type="button" @click="pickCustomer(result)" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-slate-800" x-text="result.name"></span>
                                            <span class="block font-mono text-xs text-slate-400" x-text="result.phone || 'No phone'"></span>
                                        </span>
                                        <template x-if="result.due > 0">
                                            <span class="text-xs font-semibold text-rose-600" x-text="money(result.due)"></span>
                                        </template>
                                    </button>
                                </li>
                            </template>
                        </ul>

                        <button type="button" @click="newCustomer = true" class="mt-2 text-sm text-brand-600 hover:underline">+ New customer</button>
                    </div>

                    <div x-show="newCustomer" x-cloak class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="customer_name" class="label">Name</label>
                            <input id="customer_name" name="customer_name" type="text" maxlength="120" x-model="newName" class="input">
                        </div>
                        <div>
                            <label for="customer_phone" class="label">Phone</label>
                            <input id="customer_phone" name="customer_phone" type="text" maxlength="20" x-model="newPhone" class="input font-mono">
                        </div>
                        <button type="button" @click="clearCustomer()" class="text-left text-sm text-slate-500 hover:underline">Cancel</button>
                    </div>
                    @error('customer_name') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </section>
            </div>

            <aside class="space-y-6">
                <section class="card space-y-4 p-6">
                    <h2 class="text-base font-bold text-slate-900">Total</h2>

                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Goods (<span x-text="units"></span> units)</dt><dd class="text-slate-900" x-text="money(subtotal)"></dd></div>
                    </dl>

                    <div>
                        <label for="discount" class="label">Discount</label>
                        <input id="discount" name="discount" type="number" min="0" step="0.01" x-model="discount" class="input" placeholder="0.00">
                        @error('discount') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-between border-t border-slate-200 pt-3 text-lg font-bold">
                        <span>To pay</span>
                        <span x-text="money(total)"></span>
                    </div>
                </section>

                <section class="card space-y-4 p-6">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold text-slate-900">Payment</h2>
                        <button type="button" @click="addPayment()" x-show="payments.length < 5" class="text-sm text-brand-600 hover:underline">+ Split</button>
                    </div>

                    <template x-for="(payment, index) in payments" :key="index">
                        <div class="space-y-2 rounded-lg border border-slate-200 p-3">
                            <div class="grid grid-cols-2 gap-2">
                                <select :name="'payments[' + index + '][method]'" x-model="payment.method"
                                        @change="payment.account_id = String(defaults[payment.method] ?? payment.account_id)" class="input" aria-label="Method">
                                    @foreach($methods as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <select :name="'payments[' + index + '][account_id]'" x-model="payment.account_id" class="input" aria-label="Into account">
                                    <option value="">Choose account</option>
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex gap-2">
                                <input type="number" min="0" step="0.01" :name="'payments[' + index + '][amount]'" x-model="payment.amount"
                                       class="input flex-1" placeholder="0.00" aria-label="Amount">
                                <button type="button" @click="payFull(index)" class="btn-secondary whitespace-nowrap">Rest</button>
                                <button type="button" @click="removePayment(index)" x-show="payments.length > 1"
                                        class="px-2 text-slate-400 hover:text-rose-600" aria-label="Remove payment">&times;</button>
                            </div>
                        </div>
                    </template>

                    <p x-show="overpaid" x-cloak class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">
                        That is more than the total. Record only what the sale comes to and hand the rest back as change.
                    </p>

                    <dl class="space-y-2 border-t border-slate-200 pt-3 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Taken</dt><dd class="text-slate-900" x-text="money(taken)"></dd></div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Left on account</dt>
                            <dd :class="remaining > 0 ? 'font-semibold text-rose-600' : 'text-slate-900'" x-text="money(remaining)"></dd>
                        </div>
                    </dl>

                    <p x-show="remaining > 0 && ! customer" x-cloak class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        A walk-in sale cannot leave money owing — nobody to collect it from. Choose a customer, or take the full amount.
                    </p>

                    <div class="border-t border-slate-200 pt-3">
                        <label for="cash_given" class="label">Cash handed over <span class="text-slate-400">(to work out change)</span></label>
                        <input id="cash_given" type="number" min="0" step="0.01" x-model="cashGiven" class="input" placeholder="0.00">
                        <div class="mt-2 flex justify-between text-sm font-bold" x-show="Number(cashGiven) > 0" x-cloak>
                            <span>Change to give</span>
                            <span class="text-emerald-700" x-text="money(change)"></span>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">Nothing is recorded from this box; it only works out the change.</p>
                    </div>
                </section>

                <section class="card p-6">
                    <label for="note" class="label">Note <span class="text-slate-400">(optional)</span></label>
                    <textarea id="note" name="note" rows="2" maxlength="500" x-model="note" class="input"></textarea>
                </section>

                <button type="submit" class="btn-primary w-full py-3 text-base"
                        :disabled="! lines.length || overpaid || submitting || (remaining > 0 && ! customer)">
                    <span x-show="! submitting">Complete sale</span>
                    <span x-show="submitting" x-cloak>Recording…</span>
                </button>
            </aside>
        </div>
    </form>
@endsection
