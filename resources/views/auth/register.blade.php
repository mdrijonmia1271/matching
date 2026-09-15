@extends('layouts.app')

@section('title', 'Create account')

@section('content')
    <div class="mx-auto max-w-md px-4 py-16 sm:px-6">
        <div class="card p-8">
            <h1 class="text-2xl font-bold text-slate-900">Create your account</h1>
            <p class="mt-1 text-sm text-slate-500">It takes less than a minute.</p>

            <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="name" class="label">Full name</label>
                    <input id="name" name="name" type="text" required autofocus value="{{ old('name') }}" class="input">
                </div>

                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" name="email" type="email" required autocomplete="email" value="{{ old('email') }}" class="input">
                </div>

                <div>
                    <label for="phone" class="label">Phone <span class="text-slate-400">(optional)</span></label>
                    <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" class="input" placeholder="01XXXXXXXXX">
                </div>

                <div>
                    <label for="password" class="label">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password" class="input">
                    <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
                </div>

                <div>
                    <label for="password_confirmation" class="label">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input">
                </div>

                <button type="submit" class="btn-primary w-full">Create account</button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                Already have an account?
                <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:underline">Log in</a>
            </p>
        </div>
    </div>
@endsection
