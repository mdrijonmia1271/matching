<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class BrandController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index']),
            new Middleware('can:products.create', only: ['store']),
            new Middleware('can:products.edit', only: ['edit', 'update']),
            new Middleware('can:products.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        return view('admin.brands.index', [
            'brands' => Brand::withCount('products')
                ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->string('q')->trim() . '%'))
                ->orderBy('name')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request)
    {
        $brand = Brand::create($this->validated($request));

        AuditLogger::log('products', 'brand_created', $brand, 'Brand ' . $brand->name . ' created');

        return back()->with('success', 'Brand ' . $brand->name . ' added.');
    }

    public function edit(Brand $brand)
    {
        return view('admin.brands.edit', compact('brand'));
    }

    public function update(Request $request, Brand $brand)
    {
        $brand->fill($this->validated($request, $brand));
        [$old, $new] = AuditLogger::changes($brand);
        $brand->save();

        if ($new) {
            AuditLogger::log('products', 'brand_updated', $brand, 'Brand ' . $brand->name . ' updated', $old, $new);
        }

        return redirect()->route('admin.brands.index')->with('success', 'Brand updated.');
    }

    public function destroy(Brand $brand)
    {
        if ($brand->products()->withTrashed()->exists()) {
            return back()->with('error', 'This brand is used by products. Change those products first.');
        }

        $brand->delete();

        AuditLogger::log('products', 'brand_deleted', null, 'Brand ' . $brand->name . ' deleted');

        return back()->with('success', 'Brand deleted.');
    }

    protected function validated(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('brands', 'name')->ignore($brand?->id)],
        ]) + ['is_active' => $brand ? $request->boolean('is_active') : true];
    }
}
