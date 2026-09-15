@extends('layouts.admin')

@section('title', $category->exists ? 'Edit category' : 'New category')
@section('heading', $category->exists ? 'Edit category' : 'New category')

@section('content')
    <form method="POST" enctype="multipart/form-data"
          action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}"
          class="card max-w-2xl p-6">
        @csrf
        @if($category->exists) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="name" class="label">Name</label>
                <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $category->name) }}" class="input">
            </div>

            <div class="sm:col-span-2">
                <label for="parent_id" class="label">Parent category</label>
                <select id="parent_id" name="parent_id" class="input">
                    <option value="">None — this is a main category</option>
                    @foreach($parents as $parent)
                        <option value="{{ $parent->id }}" @selected(old('parent_id', $category->parent_id) == $parent->id)>{{ $parent->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">Choose a parent to make this a subcategory (for example Kurti &rsaquo; Cotton Kurti).</p>
            </div>

            <div>
                <label for="slug" class="label">Slug <span class="text-slate-400">(auto if blank)</span></label>
                <input id="slug" name="slug" type="text" maxlength="150" value="{{ old('slug', $category->slug) }}" class="input">
            </div>

            <div>
                <label for="sort_order" class="label">Sort order</label>
                <input id="sort_order" name="sort_order" type="number" min="0" value="{{ old('sort_order', $category->sort_order ?? 0) }}" class="input">
            </div>

            <div class="sm:col-span-2">
                <label for="description" class="label">Description</label>
                <textarea id="description" name="description" rows="3" maxlength="1000" class="input">{{ old('description', $category->description) }}</textarea>
            </div>

            <div class="sm:col-span-2">
                <label for="image" class="label">Image</label>
                <input id="image" name="image" type="file" accept="image/*" class="input p-2">
                @if($category->image)
                    <img src="{{ asset('storage/' . $category->image) }}" alt="" class="mt-3 h-24 w-24 rounded-lg object-cover">
                @endif
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active ?? true)) class="rounded text-brand-600 focus:ring-brand-500">
                Visible in the shop
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary">{{ $category->exists ? 'Save changes' : 'Create category' }}</button>
            <a href="{{ route('admin.categories.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
@endsection
