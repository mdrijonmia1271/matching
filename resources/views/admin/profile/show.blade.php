@extends('layouts.admin')

@section('title', 'My profile')
@section('heading', 'My profile')

@section('content')
    <div class="grid max-w-4xl gap-6 lg:grid-cols-[1fr_320px]">
        <form method="POST" action="{{ route('admin.profile.update') }}" class="card p-6">
            @csrf @method('PATCH')

            <h2 class="text-base font-bold text-slate-900">Account details</h2>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="label">Full name</label>
                    <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $user->name) }}" class="input">
                </div>
                <div>
                    <label for="email" class="label">Email (used to log in)</label>
                    <input id="email" name="email" type="email" required maxlength="150" value="{{ old('email', $user->email) }}" class="input">
                </div>
                <div>
                    <label for="phone" class="label">Phone <span class="text-slate-400">(optional)</span></label>
                    <input id="phone" name="phone" type="tel" maxlength="30" value="{{ old('phone', $user->phone) }}" class="input">
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button class="btn-primary">Save changes</button>
            </div>
        </form>

        <aside class="card h-fit p-6 text-center">
            <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-brand-100 text-2xl font-bold text-brand-700">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </span>
            <p class="mt-3 font-semibold text-slate-900">{{ $user->name }}</p>
            <p class="text-sm text-slate-500">{{ $user->email }}</p>

            <dl class="mt-5 space-y-2 border-t border-slate-100 pt-4 text-left text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Role</dt>
                    <dd class="font-medium text-slate-800">{{ $user->role?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Member since</dt>
                    <dd class="font-medium text-slate-800">{{ $user->created_at?->format('d M Y') }}</dd>
                </div>
            </dl>

            <a href="{{ route('admin.profile.password') }}" class="btn-secondary mt-5 w-full">Change password</a>
        </aside>
    </div>
@endsection
