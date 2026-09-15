@extends('layouts.admin')

@section('title', 'Staff')
@section('heading', 'Staff')

@section('content')
    <div class="card">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, email or phone" class="input w-56">

                <select name="role" class="input w-44">
                    <option value="">All roles</option>
                    @foreach($roles as $role)
                        <option value="{{ $role->id }}" @selected(request('role') == $role->id)>{{ $role->name }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'role']))
                    <a href="{{ route('admin.staff.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>

            <div class="ml-auto flex gap-2">
                <a href="{{ route('admin.roles.index') }}" class="btn-secondary">Roles &amp; permissions</a>
                @can('staff.create')
                    <a href="{{ route('admin.staff.create') }}" class="btn-primary">Add staff</a>
                @endcan
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Phone</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Last login</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($staff as $member)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <p class="font-medium text-slate-900">{{ $member->name }} @if($member->is(auth()->user()))<span class="text-xs text-slate-400">(you)</span>@endif</p>
                                <p class="text-xs text-slate-400">{{ $member->email }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $member->phone ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold text-brand-700">{{ $member->role?->name ?? 'No role' }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs font-semibold {{ $member->is_active ? 'text-emerald-600' : 'text-slate-400' }}">
                                {{ $member->is_active ? 'Active' : 'Deactivated' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $member->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    @can('staff.edit')
                                        <a href="{{ route('admin.staff.edit', $member) }}" class="text-xs font-semibold text-brand-600 hover:underline">Edit</a>
                                    @endcan
                                    @can('staff.delete')
                                        @if($member->is_active && ! $member->is(auth()->user()))
                                            <form method="POST" action="{{ route('admin.staff.destroy', $member) }}"
                                                  onsubmit="return confirm('Deactivate {{ e($member->name) }}? They will be logged out and unable to log in.')">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-rose-600 hover:underline">Deactivate</button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No staff match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $staff->links() }}</div>
@endsection
