@extends('layouts.admin')

@section('title', 'Edit brand')
@section('heading', 'Edit brand')

@section('content')
    <form method="POST" action="{{ route('admin.brands.update', $brand) }}" enctype="multipart/form-data" class="card max-w-lg p-6">
        @csrf @method('PUT')

        <label for="name" class="label">Name</label>
        <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $brand->name) }}" class="input">

        <label for="logo" class="label mt-4">Logo</label>
        <input id="logo" name="logo" type="file" accept=".png,.jpg,.jpeg,.webp" @required(! $brand->logo) class="input p-2">
        @if($brand->logo)
            <div class="mt-3 flex items-center gap-3">
                <img src="{{ $brand->logo_url }}" alt="{{ $brand->name }}" class="h-12 w-28 object-contain">
                <span class="text-xs text-slate-500">Current logo. Choose a file only to replace it.</span>
            </div>
        @else
            <p class="mt-1 text-xs text-amber-600">This brand has no logo yet, so it is not shown on the home page. PNG, JPG or WebP, up to 2 MB.</p>
        @endif

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
