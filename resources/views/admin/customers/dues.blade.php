@extends('layouts.admin')

@section('title', 'Customer dues')
@section('heading', 'Customer dues')

@section('content')
    @php
        $buckets = [
            'opening' => ['Opening due', 'Owed before this system'],
            '0-30' => ['0–30 days', 'Unpaid orders by age'],
            '31-60' => ['31–60 days', 'Unpaid orders by age'],
            '61-90' => ['61–90 days', 'Unpaid orders by age'],
            '90+' => ['Over 90 days', 'Unpaid orders by age'],
        ];
    @endphp

    <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
        <div class="card bg-slate-900 p-5 text-white">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total due</p>
            <p class="mt-2 text-2xl font-bold">@money($ageing['total'])</p>
            <p class="text-xs text-slate-400">{{ number_format($customersWithDue) }} {{ \Illuminate\Support\Str::plural('customer', $customersWithDue) }}</p>
        </div>
        @foreach($buckets as $key => [$label, $hint])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-xl font-bold {{ $key === '90+' && $ageing[$key] > 0 ? 'text-rose-600' : 'text-slate-900' }}">@money($ageing[$key])</p>
                <p class="text-xs text-slate-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <div class="card mt-6">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone or email" class="input w-60">

                <select name="group" class="input w-36" aria-label="Customer group">
                    <option value="">All groups</option>
                    @foreach(\App\Models\Customer::GROUPS as $key => $label)
                        <option value="{{ $key }}" @selected(request('group') === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="age" class="input w-48" aria-label="Age">
                    <option value="">Any age</option>
                    @foreach($ages as $key => $label)
                        <option value="{{ $key }}" @selected((string) request('age') === (string) $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="sort" class="input w-40" aria-label="Sort">
                    @foreach($sorts as $key => $label)
                        <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'group', 'age', 'sort']))
                    <a href="{{ route('admin.customer-dues.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>

            @can('reports.export')
                <a href="{{ route('admin.customer-dues.export', request()->query()) }}" class="btn-secondary ml-auto">Export CSV</a>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Phone</th>
                        <th class="px-4 py-3 text-right">Opening due</th>
                        <th class="px-4 py-3 text-right">Order due</th>
                        <th class="px-4 py-3 text-right">Total due</th>
                        <th class="px-4 py-3">Oldest unpaid order</th>
                        <th class="px-4 py-3">Last payment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($customers as $customer)
                        @php
                            $openingDue = (float) $customer->opening_due_remaining;
                            $orderDue = round((float) $customer->current_due - $openingDue, 2);
                            $days = $customer->oldest_unpaid_at ? (int) $customer->oldest_unpaid_at->diffInDays(now()) : null;
                            $lastPaid = collect([$customer->last_payment_at, $customer->last_collection_at])->filter()->max();
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.customers.show', $customer) }}" class="font-semibold text-brand-600 hover:underline">{{ $customer->name }}</a>
                                <span class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $customer->groupColor() }}">{{ $customer->group_label }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $customer->phone ?: '—' }}</td>
                            <td class="px-4 py-3 text-right {{ $openingDue > 0 ? 'text-slate-900' : 'text-slate-400' }}">@money($openingDue)</td>
                            <td class="px-4 py-3 text-right {{ $orderDue > 0 ? 'text-slate-900' : 'text-slate-400' }}">@money($orderDue)</td>
                            <td class="px-4 py-3 text-right font-semibold text-rose-600">@money($customer->current_due)</td>
                            <td class="px-4 py-3 text-xs">
                                @if($days !== null)
                                    <span class="text-slate-700">{{ $customer->oldest_unpaid_at->format('d M Y') }}</span>
                                    <span class="block {{ $days > 90 ? 'font-semibold text-rose-600' : ($days > 30 ? 'text-amber-600' : 'text-slate-400') }}">{{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }}</span>
                                @else
                                    <span class="text-slate-400">Opening due only</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $lastPaid?->format('d M Y') ?? 'Never' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No customer owes money for these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $customers->links() }}</div>
@endsection
