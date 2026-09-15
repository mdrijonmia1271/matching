@extends('layouts.app')

@section('title', 'Account settings')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <h1 class="text-2xl font-bold text-slate-900">Account settings</h1>

        <div class="mt-8 space-y-6">
            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Profile</h2>
                <p class="mt-1 text-sm text-slate-500">Used to pre-fill your checkout details.</p>

                <form method="POST" action="{{ route('profile.update') }}" class="mt-5 grid gap-4 sm:grid-cols-2">
                    @csrf @method('PATCH')

                    <div>
                        <label for="name" class="label">Full name</label>
                        <input id="name" name="name" type="text" required value="{{ old('name', $user->name) }}" class="input">
                    </div>
                    <div>
                        <label for="phone" class="label">Phone</label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" class="input">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="email" class="label">Email</label>
                        <input id="email" name="email" type="email" required value="{{ old('email', $user->email) }}" class="input">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="address" class="label">Default delivery address</label>
                        <textarea id="address" name="address" rows="3" class="input">{{ old('address', $user->address) }}</textarea>
                    </div>

                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary">Save changes</button>
                    </div>
                </form>
            </section>

            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Password</h2>

                <form method="POST" action="{{ route('profile.password') }}" class="mt-5 grid gap-4 sm:grid-cols-2">
                    @csrf @method('PATCH')

                    <div class="sm:col-span-2">
                        <label for="current_password" class="label">Current password</label>
                        <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="input">
                    </div>
                    <div>
                        <label for="new_password" class="label">New password</label>
                        <input id="new_password" name="password" type="password" required autocomplete="new-password" class="input">
                    </div>
                    <div>
                        <label for="password_confirmation" class="label">Confirm new password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input">
                    </div>

                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary">Change password</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
@endsection
