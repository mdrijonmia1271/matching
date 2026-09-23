@extends('layouts.admin')

@section('title', 'Newsletter')
@section('heading', 'Newsletter')

@section('content')
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Subscribers</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['subscribed']) }}</p>
            <p class="text-xs text-slate-400">On the list right now</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">New this month</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['this_month']) }}</p>
            <p class="text-xs text-slate-400">Signed up since {{ now()->startOfMonth()->format('d M') }}</p>
        </div>
        <a href="{{ route('admin.newsletter.index', ['status' => 'unsubscribed']) }}" class="card p-5 transition hover:border-brand-300">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Unsubscribed</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['unsubscribed']) }}</p>
            <p class="text-xs text-slate-400">Show them</p>
        </a>
    </div>

    <div class="card mt-6">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Email address" class="input w-60">

                <select name="status" class="input w-40" aria-label="Status">
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="sort" class="input w-40" aria-label="Sort">
                    @foreach($sorts as $key => $label)
                        <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'status', 'sort']))
                    <a href="{{ route('admin.newsletter.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>

            @can('reports.export')
                <a href="{{ route('admin.newsletter.export', request()->only(['q', 'status', 'sort'])) }}" class="btn-secondary ml-auto">Export CSV</a>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Source</th>
                        <th class="px-4 py-3">Subscribed</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($subscribers as $subscriber)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $subscriber->email }}</td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $subscriber->source }}</td>
                            <td class="px-4 py-3 text-slate-700">
                                {{ $subscriber->subscribed_at?->format('d M Y, g:i a') ?? '—' }}
                                @if($subscriber->unsubscribed_at)
                                    <span class="block text-xs text-slate-400">Left {{ $subscriber->unsubscribed_at->format('d M Y') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center text-xs">
                                @if($subscriber->isSubscribed())
                                    <span class="text-emerald-600">Subscribed</span>
                                @else
                                    <span class="text-slate-400">Unsubscribed</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <form method="POST" action="{{ route('admin.newsletter.toggle', $subscriber) }}">
                                        @csrf @method('PATCH')
                                        <button class="text-xs font-semibold text-brand-600 hover:underline">
                                            {{ $subscriber->isSubscribed() ? 'Unsubscribe' : 'Resubscribe' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.newsletter.destroy', $subscriber) }}"
                                          onsubmit="return confirm('Delete this address for good?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-rose-600 hover:underline">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No subscribers here yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $subscribers->links() }}</div>
@endsection
