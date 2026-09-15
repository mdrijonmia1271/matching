@extends('layouts.admin')

@section('title', $member->exists ? 'Edit staff' : 'Add staff')
@section('heading', $member->exists ? 'Edit ' . $member->name : 'Add staff')

@section('content')
    @php $isSelf = $member->exists && $member->is(auth()->user()); @endphp

    <form method="POST" action="{{ $member->exists ? route('admin.staff.update', $member) : route('admin.staff.store') }}"
          class="grid max-w-4xl gap-6 lg:grid-cols-[1fr_320px]">
        @csrf
        @if($member->exists) @method('PUT') @endif

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Account</h2>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="label">Full name</label>
                    <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $member->name) }}" class="input">
                </div>
                <div>
                    <label for="email" class="label">Email (used to log in)</label>
                    <input id="email" name="email" type="email" required maxlength="150" value="{{ old('email', $member->email) }}" class="input">
                </div>
                <div>
                    <label for="phone" class="label">Phone <span class="text-slate-400">(optional)</span></label>
                    <input id="phone" name="phone" type="tel" maxlength="30" value="{{ old('phone', $member->phone) }}" class="input">
                </div>
                <div>
                    <label for="password" class="label">{{ $member->exists ? 'New password' : 'Password' }}</label>
                    <input id="password" name="password" type="password" @required(! $member->exists) autocomplete="new-password" class="input">
                    <p class="mt-1 text-xs text-slate-500">At least 8 characters with letters and numbers.{{ $member->exists ? ' Leave blank to keep the current password.' : '' }}</p>
                </div>
                <div>
                    <label for="password_confirmation" class="label">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="input">
                </div>
            </div>
        </section>

        <aside class="space-y-6">
            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Access</h2>

                <div class="mt-4 space-y-4">
                    <div>
                        <label for="role_id" class="label">Role</label>
                        <select id="role_id" name="role_id" required class="input" @disabled($isSelf)>
                            <option value="">Select a role</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->id }}" @selected(old('role_id', $member->role_id) == $role->id)>{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @if($isSelf)
                            <input type="hidden" name="role_id" value="{{ $member->role_id }}">
                            <p class="mt-1 text-xs text-slate-500">You cannot change your own role.</p>
                        @else
                            <a href="{{ route('admin.roles.index') }}" class="mt-1 inline-block text-xs text-brand-600 hover:underline">What can each role do?</a>
                        @endif
                    </div>

                    @if($member->exists && ! $isSelf)
                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $member->is_active)) class="mt-0.5 rounded text-brand-600 focus:ring-brand-500">
                            <span>Account is active<span class="block text-xs text-slate-500">Unticking logs them out and blocks login.</span></span>
                        </label>
                    @endif
                </div>
            </section>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">{{ $member->exists ? 'Save changes' : 'Create account' }}</button>
                <a href="{{ route('admin.staff.index') }}" class="btn-secondary">Cancel</a>
            </div>
        </aside>
    </form>
@endsection
