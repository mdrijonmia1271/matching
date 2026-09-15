@extends('layouts.admin')

@section('title', 'Activity log')
@section('heading', 'Activity log')

@section('content')
    <div class="card">
        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search description" class="input w-56">

            <select name="module" class="input w-40">
                <option value="">All modules</option>
                @foreach($modules as $module)
                    <option value="{{ $module }}" @selected(request('module') === $module)>{{ ucfirst($module) }}</option>
                @endforeach
            </select>

            <select name="action" class="input w-44">
                <option value="">All actions</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ ucfirst(str_replace('_', ' ', $action)) }}</option>
                @endforeach
            </select>

            <select name="user" class="input w-44">
                <option value="">Anyone</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}" @selected(request('user') == $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>

            <input type="date" name="from" value="{{ request('from') }}" class="input w-40" aria-label="From date">
            <input type="date" name="to" value="{{ request('to') }}" class="input w-40" aria-label="To date">

            <button type="submit" class="btn-secondary">Filter</button>
            @if(request()->hasAny(['q', 'module', 'action', 'user', 'from', 'to']))
                <a href="{{ route('admin.activity.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
            @endif
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">When</th>
                        <th class="px-4 py-3">Who</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($logs as $log)
                        <tr class="align-top hover:bg-slate-50" x-data="{ open: false }">
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                {{ $log->created_at?->format('d M Y') }}
                                <span class="block text-xs text-slate-400">{{ $log->created_at?->format('h:i A') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-slate-800">{{ $log->user?->name ?? 'System / customer' }}</p>
                                <p class="text-xs text-slate-400">{{ $log->ip_address }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">{{ ucfirst($log->module) }}</span>
                                <span class="mt-1 block text-xs text-slate-500">{{ $log->action_label }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-slate-800">{{ $log->description }}</p>
                                @if($log->old_values || $log->new_values)
                                    <button type="button" @click="open = ! open" class="mt-1 text-xs text-brand-600 hover:underline" x-text="open ? 'Hide changes' : 'Show changes'"></button>
                                    <div x-show="open" x-cloak class="mt-2 overflow-x-auto rounded-lg border border-slate-200">
                                        <table class="w-full text-xs">
                                            <thead class="bg-slate-50 text-left text-slate-500">
                                                <tr><th class="px-3 py-1.5">Field</th><th class="px-3 py-1.5">Before</th><th class="px-3 py-1.5">After</th></tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 font-mono">
                                                @foreach(array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))) as $field)
                                                    @php
                                                        $show = fn ($value) => is_array($value) ? implode(', ', array_map(fn ($v) => is_scalar($v) ? $v : json_encode($v), $value)) : (is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? '—'));
                                                    @endphp
                                                    <tr>
                                                        <td class="px-3 py-1.5 text-slate-500">{{ $field }}</td>
                                                        <td class="px-3 py-1.5 text-rose-700">{{ $show(($log->old_values ?? [])[$field] ?? null) }}</td>
                                                        <td class="px-3 py-1.5 text-emerald-700">{{ $show(($log->new_values ?? [])[$field] ?? null) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">No activity recorded for these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $logs->links() }}</div>
@endsection
