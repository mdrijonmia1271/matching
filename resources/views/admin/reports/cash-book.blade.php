@extends($printing ? 'layouts.report-print' : 'layouts.admin')

@section('title', 'Cash book')
@section('heading', 'Cash book')
@section('filters', 'Account: ' . $account?->name)

@section('content')
    @php use App\Support\Money; @endphp

    @unless($printing)

    <div class="card mb-6 p-4">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            @include('admin.reports.partials.period')
            <select name="account" class="input w-40" aria-label="Account">
                @foreach($accounts as $option)
                    <option value="{{ $option->id }}" @selected($account?->id === $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
            <button class="btn-secondary">Show</button>
        </form>
    </div>

    @endunless

    @include('admin.reports.partials.cards', ['cards' => [
        ['Opening balance', Money::format($opening), 'text-slate-900', $account?->name],
        ['Money in', Money::format($totalIn), 'text-emerald-600', null],
        ['Money out', Money::format($totalOut), 'text-rose-600', null],
        ['Closing balance', Money::format($closing), $closing < 0 ? 'text-rose-600' : 'text-brand-600', null],
    ]])

    <div class="card mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    @if($printing)<th class="sl px-4 py-3">SL</th>@endif
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Details</th>
                    <th class="px-4 py-3 text-right">In</th>
                    <th class="px-4 py-3 text-right">Out</th>
                    <th class="px-4 py-3 text-right">Balance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr class="bg-slate-50">
                    @if($printing)<td class="sl px-4 py-3"></td>@endif
                    <td class="px-4 py-3 text-slate-600">{{ $from->format('d M Y') }}</td>
                    <td class="px-4 py-3 font-semibold text-slate-700" colspan="4">Opening balance</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-900">{{ Money::format($opening) }}</td>
                </tr>
                @forelse($entries as $entry)
                    <tr class="hover:bg-slate-50">
                        @if($printing)<td class="sl px-4 py-3">{{ $loop->iteration }}</td>@endif
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $entry->transacted_at->format('d M Y, g:i a') }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $entry->type_label }}</td>
                        <td class="px-4 py-3 text-slate-500">
                            {{ $entry->note ?: '—' }}
                            @if($entry->user)<span class="block text-xs text-slate-400">{{ $entry->user->name }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-emerald-700">{{ $entry->direction === 'in' ? Money::format((float) $entry->amount) : '' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-rose-600">{{ $entry->direction === 'out' ? Money::format((float) $entry->amount) : '' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold {{ $entry->running_balance < 0 ? 'text-rose-600' : 'text-slate-900' }}">{{ Money::format($entry->running_balance) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 6 + ($printing ? 1 : 0) }}" class="px-4 py-8 text-center text-slate-500">No entries in this period.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-slate-900 font-bold">
                    <td class="px-4 py-3 text-slate-900" colspan="{{ 3 + ($printing ? 1 : 0) }}">Closing balance · {{ $to->format('d M Y') }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-emerald-700">{{ Money::format($totalIn) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-rose-600">{{ Money::format($totalOut) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-900">{{ Money::format($closing) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
@endsection
