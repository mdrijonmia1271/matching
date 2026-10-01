@extends('layouts.admin')

@section('title', 'Change password')
@section('heading', 'Change password')

@section('content')
    <form method="POST" action="{{ route('admin.profile.password.update') }}" class="card max-w-xl p-6" x-data="{ show: false }">
        @csrf @method('PUT')

        <h2 class="text-base font-bold text-slate-900">Change your password</h2>
        <p class="mt-1 text-sm text-slate-500">At least 8 characters with letters and numbers.</p>

        <div class="mt-5 space-y-4">
            @foreach([
                ['current_password', 'Current password', 'current-password'],
                ['password', 'New password', 'new-password'],
                ['password_confirmation', 'Confirm new password', 'new-password'],
            ] as [$field, $label, $autocomplete])
                <div>
                    <label for="{{ $field }}" class="label">{{ $label }}</label>
                    <div class="relative">
                        <input id="{{ $field }}" name="{{ $field }}" x-bind:type="show ? 'text' : 'password'"
                               required autocomplete="{{ $autocomplete }}" class="input pr-11">
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
            @endforeach
        </div>

        <div class="mt-6 flex justify-end">
            <button class="btn-primary">Update password</button>
        </div>
    </form>
@endsection
