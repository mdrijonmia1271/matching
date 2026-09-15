@extends('layouts.admin')

@section('title', $role->exists ? 'Edit role' : 'Add role')
@section('heading', $role->exists ? 'Edit role: ' . $role->name : 'Add role')

@section('content')
    @php
        $locked = $role->exists && $role->isSuperAdmin();
        $selected = old('permissions', $role->exists ? $role->permissionKeys() : []);
    @endphp

    <form method="POST" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="max-w-5xl space-y-6">
        @csrf
        @if($role->exists) @method('PUT') @endif

        <section class="card grid gap-4 p-6 sm:grid-cols-2">
            <div>
                <label for="name" class="label">Role name</label>
                <input id="name" name="name" type="text" required maxlength="60" value="{{ old('name', $role->name) }}" class="input">
            </div>
            <div>
                <label for="description" class="label">Description <span class="text-slate-400">(optional)</span></label>
                <input id="description" name="description" type="text" maxlength="255" value="{{ old('description', $role->description) }}" class="input">
            </div>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Permissions</h2>
            @if($locked)
                <p class="mt-1 text-sm text-slate-500">Super Admin always has every permission, including ones added in future.</p>
            @else
                <p class="mt-1 text-sm text-slate-500">You can only grant permissions that your own role has.</p>
            @endif

            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach($groups as $group => $permissions)
                    <fieldset class="rounded-lg border border-slate-200 p-4" x-data>
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-slate-900">{{ $group }}</p>
                            @unless($locked)
                                <button type="button" class="text-xs text-brand-600 hover:underline"
                                        @click="const boxes = [...$el.closest('fieldset').querySelectorAll('input[type=checkbox]')]; const all = boxes.every(b => b.checked); boxes.forEach(b => b.checked = ! all)">
                                    Toggle all
                                </button>
                            @endunless
                        </div>

                        <div class="mt-3 space-y-2">
                            @foreach($permissions as $key => $label)
                                <label class="flex items-start gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="permissions[]" value="{{ $key }}"
                                           @checked($locked || in_array($key, $selected, true)) @disabled($locked)
                                           class="mt-0.5 rounded text-brand-600 focus:ring-brand-500">
                                    <span>{{ $label }}<span class="block font-mono text-[11px] text-slate-400">{{ $key }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
        </section>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary">{{ $role->exists ? 'Save role' : 'Create role' }}</button>
            <a href="{{ route('admin.roles.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
@endsection
