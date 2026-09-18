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

            <div class="mt-5 grid gap-4 sm:grid-cols-2" x-data="{ show: false }">
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
                    <div class="relative">
                        <input id="password" name="password" x-bind:type="show ? 'text' : 'password'"
                               @required(! $member->exists) autocomplete="new-password" class="input pr-11">
                        <button type="button" @click="show = ! show" tabindex="-1"
                                :aria-label="show ? 'Hide password' : 'Show password'" :aria-pressed="show"
                                class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition hover:text-slate-700">
                            <svg x-show="! show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3.2"/>
                            </svg>
                            <svg x-show="show" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" d="M4 4l16 16M9.9 5.9A9.6 9.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-3.3 4.1M6.6 7.9A17 17 0 0 0 2.5 12S6 18.5 12 18.5c1 0 1.9-.2 2.7-.5"/>
                                <path d="M9.9 10.1a3.2 3.2 0 0 0 4.3 4.3"/>
                            </svg>
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">At least 8 characters with letters and numbers.{{ $member->exists ? ' Leave blank to keep the current password.' : '' }}</p>
                </div>
                <div>
                    <label for="password_confirmation" class="label">Confirm password</label>
                    <div class="relative">
                        <input id="password_confirmation" name="password_confirmation" x-bind:type="show ? 'text' : 'password'"
                               autocomplete="new-password" class="input pr-11">
                        <button type="button" @click="show = ! show" tabindex="-1"
                                :aria-label="show ? 'Hide password' : 'Show password'" :aria-pressed="show"
                                class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition hover:text-slate-700">
                            <svg x-show="! show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3.2"/>
                            </svg>
                            <svg x-show="show" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" d="M4 4l16 16M9.9 5.9A9.6 9.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-3.3 4.1M6.6 7.9A17 17 0 0 0 2.5 12S6 18.5 12 18.5c1 0 1.9-.2 2.7-.5"/>
                                <path d="M9.9 10.1a3.2 3.2 0 0 0 4.3 4.3"/>
                            </svg>
                        </button>
                    </div>
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
