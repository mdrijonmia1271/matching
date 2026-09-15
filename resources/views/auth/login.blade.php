@extends('layouts.app')

@section('title', 'Log in')

@section('content')
    <div class="mx-auto max-w-md px-4 py-16 sm:px-6">
        <div class="card p-8">
            <h1 class="text-2xl font-bold text-slate-900">Welcome back</h1>
            <p class="mt-1 text-sm text-slate-500">Log in to track orders and check out faster.</p>

            <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" name="email" type="email" required autofocus autocomplete="email"
                           value="{{ old('email') }}" class="input">
                </div>

                <div>
                    <label for="password" class="label">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" value="1" class="rounded text-brand-600 focus:ring-brand-500">
                    Remember me
                </label>

                <button type="submit" class="btn-primary w-full">Log in</button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                New here?
                <a href="{{ route('register') }}" class="font-semibold text-brand-600 hover:underline">Create an account</a>
            </p>
        </div>
    </div>
@endsection
