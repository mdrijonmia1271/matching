@extends('layouts.admin')

@section('title', 'Edit brand')
@section('heading', 'Edit brand')

@section('content')
    <form method="POST" action="{{ route('admin.brands.update', $brand) }}" class="card max-w-lg p-6">
        @csrf @method('PUT')

        <label for="name" class="label">Name</label>
        <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $brand->name) }}" class="input">

        <label class="mt-4 flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $brand->is_active)) class="rounded text-brand-600 focus:ring-brand-500">
            Active
        </label>

        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary">Save</button>
            <a href="{{ route('admin.brands.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
@endsection
