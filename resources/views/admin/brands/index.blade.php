@extends('layouts.admin')

@section('title', 'Brands')
@section('heading', 'Brands')

@section('content')
    <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="card">
            <form method="GET" class="flex items-center gap-2 border-b border-slate-200 p-4">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Search brands" class="input w-56">
                <button type="submit" class="btn-secondary">Search</button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Logo</th>
                            <th class="px-4 py-3">Brand</th>
                            <th class="px-4 py-3 text-center">Products</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($brands as $brand)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    @if($brand->logo)
                                        <img src="{{ $brand->logo_url }}" alt="{{ $brand->name }}" class="h-10 w-20 object-contain">
                                    @else
                                        <span class="text-xs text-amber-600">No logo</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $brand->name }}</td>
                                <td class="px-4 py-3 text-center text-slate-600">
                                    <a href="{{ route('admin.products.index', ['brand' => $brand->id]) }}" class="hover:underline">{{ $brand->products_count }}</a>
                                </td>
                                <td class="px-4 py-3 text-center text-xs {{ $brand->is_active ? 'text-emerald-600' : 'text-slate-400' }}">{{ $brand->is_active ? 'Active' : 'Inactive' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        @can('products.edit')
                                            <a href="{{ route('admin.brands.edit', $brand) }}" class="text-xs font-semibold text-brand-600 hover:underline">Edit</a>
                                        @endcan
                                        @can('products.delete')
                                            <form method="POST" action="{{ route('admin.brands.destroy', $brand) }}" onsubmit="return confirm('Delete {{ e($brand->name) }}?')">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-rose-600 hover:underline">Delete</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No brands yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @can('products.create')
            <form method="POST" action="{{ route('admin.brands.store') }}" enctype="multipart/form-data" class="card h-fit p-6">
                @csrf
                <h2 class="text-base font-bold text-slate-900">Add brand</h2>
                <label for="name" class="label mt-4">Name</label>
                <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name') }}" class="input">
                <label for="logo" class="label mt-4">Logo</label>
                <input id="logo" name="logo" type="file" accept=".png,.jpg,.jpeg,.webp" required class="input p-2">
                <p class="mt-1 text-xs text-slate-500">Shown on the home page brand strip. PNG on a transparent background works best. PNG, JPG or WebP, up to 2 MB.</p>

                <button type="submit" class="btn-primary mt-4 w-full">Add brand</button>
            </form>
        @endcan
    </div>

    <div class="mt-6">{{ $brands->links() }}</div>
@endsection
