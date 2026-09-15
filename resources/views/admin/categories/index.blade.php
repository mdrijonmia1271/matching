@extends('layouts.admin')

@section('title', 'Categories')
@section('heading', 'Categories')

@section('content')
    <div class="card">
        <div class="flex items-center justify-between border-b border-slate-200 p-4">
            <p class="text-sm text-slate-500">{{ $categories->count() }} main categories, {{ $categories->sum(fn ($c) => $c->children->count()) }} subcategories</p>
            @can('products.create')
                <a href="{{ route('admin.categories.create') }}" class="btn-primary">Add category</a>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Slug</th>
                        <th class="px-4 py-3 text-center">Products</th>
                        <th class="px-4 py-3 text-center">Order</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($categories as $category)
                        @foreach([$category, ...$category->children] as $row)
                            @php $isChild = $row->parent_id !== null; @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3 {{ $isChild ? 'pl-10' : '' }}">
                                        @if($isChild)
                                            <span class="text-slate-300">&rsaquo;</span>
                                        @else
                                            <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-lg bg-brand-50 text-sm font-bold text-brand-700">
                                                @if($row->image)
                                                    <img src="{{ asset('storage/' . $row->image) }}" alt="" class="h-full w-full object-cover">
                                                @else
                                                    {{ strtoupper(substr($row->name, 0, 1)) }}
                                                @endif
                                            </span>
                                        @endif
                                        <span class="{{ $isChild ? 'text-slate-700' : 'font-medium text-slate-900' }}">{{ $row->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $row->slug }}</td>
                                <td class="px-4 py-3 text-center text-slate-700">{{ $isChild ? $row->subcategory_products_count : $row->products_count }}</td>
                                <td class="px-4 py-3 text-center text-slate-500">{{ $row->sort_order }}</td>
                                <td class="px-4 py-3 text-center text-xs {{ $row->is_active ? 'text-emerald-600' : 'text-slate-400' }}">
                                    {{ $row->is_active ? 'Active' : 'Hidden' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        @if(! $isChild)
                                            @can('products.create')
                                                <a href="{{ route('admin.categories.create', ['parent' => $row->id]) }}" class="text-xs text-slate-500 hover:underline">+ Subcategory</a>
                                            @endcan
                                        @endif
                                        @can('products.edit')
                                            <a href="{{ route('admin.categories.edit', $row) }}" class="text-xs font-semibold text-brand-600 hover:underline">Edit</a>
                                        @endcan
                                        @can('products.delete')
                                            <form method="POST" action="{{ route('admin.categories.destroy', $row) }}"
                                                  onsubmit="return confirm('Delete {{ e($row->name) }}?')">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-rose-600 hover:underline">Delete</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No categories yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
