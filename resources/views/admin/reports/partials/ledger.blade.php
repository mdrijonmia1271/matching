{{-- Income (money in) and Cost (money out): one ledger direction, broken down by type. --}}
@php use App\Support\Money; @endphp

@unless($printing)

<div class="card mb-6 p-4">
    <form method="GET" class="flex flex-wrap items-center gap-2">
        @include('admin.reports.partials.period')
        <select name="type" class="input w-48" aria-label="Type">
            <option value="">All types</option>
            @foreach($types as $value => $label)
                <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="account" class="input w-40" aria-label="Account">
            <option value="">All accounts</option>
            @foreach($accounts as $option)
                <option value="{{ $option->id }}" @selected((int) request('account') === $option->id)>{{ $option->name }}</option>
            @endforeach
        </select>
        <button class="btn-secondary">Show</button>
    </form>
</div>

@endunless

@include('admin.reports.partials.cards', ['cards' => array_merge(
    [[$totalLabel, Money::format($total), $color, $hint]],
    $byType->take(5)->map(fn ($row) => [
        $types[$row->type] ?? ucfirst(str_replace('_', ' ', $row->type)),
        Money::format((float) $row->total),
        'text-slate-900',
        $row->entries . ' ' . \Illuminate\Support\Str::plural('entry', $row->entries),
    ])->all(),
)])

<div class="card mt-4 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                @if($printing)<th class="sl px-4 py-3">SL</th>@endif
                <th class="px-4 py-3">Date</th>
                <th class="px-4 py-3">Type</th>
                <th class="px-4 py-3">Account</th>
                <th class="px-4 py-3">Details</th>
                <th class="px-4 py-3">By</th>
                <th class="px-4 py-3 text-right">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse($rows as $entry)
                <tr class="hover:bg-slate-50">
                    @if($printing)<td class="sl px-4 py-3">{{ $loop->iteration }}</td>@endif
                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $entry->transacted_at->format('d M Y, g:i a') }}</td>
                    <td class="px-4 py-3 text-slate-700">{{ $entry->type_label }}</td>
                    <td class="px-4 py-3 text-slate-600">{{ $entry->account?->name }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $entry->note ?: '—' }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $entry->user?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold {{ $color }}">@money($entry->amount)</td>
                </tr>
            @empty
                <tr><td colspan="{{ 6 + ($printing ? 1 : 0) }}" class="px-4 py-10 text-center text-slate-500">{{ $empty }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-slate-900 font-bold">
            <td class="px-4 py-3 text-slate-900" colspan="{{ 5 + ($printing ? 1 : 0) }}">Total · {{ number_format($rows instanceof \Illuminate\Contracts\Pagination\Paginator ? $rows->total() : $rows->count()) }} entries</td>
            <td class="whitespace-nowrap px-4 py-3 text-right {{ $color }}">{{ Money::format($total) }}</td>
            </tr>
        </tfoot>
    </table>
</div>

@unless($printing)<div class="mt-6">{{ $rows->links() }}</div>@endunless
