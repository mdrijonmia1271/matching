@extends('layouts.admin')

@section('title', 'Roles & permissions')
@section('heading', 'Roles & permissions')

@section('content')
    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
            <p class="text-sm text-slate-500">Each staff member has one role. Permissions are checked on the server for every action.</p>
            @can('staff.create')
                <a href="{{ route('admin.roles.create') }}" class="btn-primary">Add role</a>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3 text-center">Staff</th>
                        <th class="px-4 py-3 text-center">Permissions</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($roles as $role)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <p class="font-medium text-slate-900">
                                    {{ $role->name }}
                                    @if($role->is_system)<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-500">Built-in</span>@endif
                                </p>
                                <p class="text-xs text-slate-500">{{ $role->description }}</p>
                            </td>
                            <td class="px-4 py-3 text-center text-slate-700">{{ $role->users_count }}</td>
                            <td class="px-4 py-3 text-center text-slate-700">
                                {{ $role->isSuperAdmin() ? 'All' : $role->permissions_count . ' / ' . $permissionTotal }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    @can('staff.edit')
                                        <a href="{{ route('admin.roles.edit', $role) }}" class="text-xs font-semibold text-brand-600 hover:underline">Edit</a>
                                    @endcan
                                    @can('staff.delete')
                                        @unless($role->is_system)
                                            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}"
                                                  onsubmit="return confirm('Delete the {{ e($role->name) }} role?')">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-rose-600 hover:underline">Delete</button>
                                            </form>
                                        @endunless
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
