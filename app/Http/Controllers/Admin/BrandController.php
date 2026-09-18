<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
                ->orderByDesc('id') // Newest first, so a brand just added is at the top.
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['logo'] = $request->file('logo')->store('brands', 'public');

        $brand = Brand::create($data);

        AuditLogger::log('products', 'brand_created', $brand, 'Brand ' . $brand->name . ' created');

        return back()->with('success', 'Brand ' . $brand->name . ' added.');
    }

    public function edit(Brand $brand)
    {
        return view('admin.brands.edit', compact('brand'));
    }

    public function update(Request $request, Brand $brand)
    {
        $data = $this->validated($request, $brand);
        $oldLogo = $brand->logo;

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('brands', 'public');
        }

        $brand->fill($data);
        [$old, $new] = AuditLogger::changes($brand);
        $brand->save();

        // Only after the row is safely saved, so a failed save never loses the old file.
        if (isset($data['logo']) && $oldLogo && $oldLogo !== $data['logo']) {
            Storage::disk('public')->delete($oldLogo);
        }

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

        $logo = $brand->logo;

        $brand->delete();

        if ($logo) {
            Storage::disk('public')->delete($logo);
        }

        AuditLogger::log('products', 'brand_deleted', null, 'Brand ' . $brand->name . ' deleted');

        return back()->with('success', 'Brand deleted.');
    }

    /**
     * Name and logo are both required: the home page brand strip is nothing
     * but logos, so a brand without one cannot be shown. On edit the existing
     * file counts, so saving other fields does not force a re-upload.
     */
    protected function validated(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('brands', 'name')->ignore($brand?->id)],
            // No SVG: it is served from our own origin, so an uploaded one could carry script.
            'logo' => [$brand?->logo ? 'nullable' : 'required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [
            'logo.required' => 'A logo is required. It is what the home page brand strip shows.',
        ]) + ['is_active' => $brand ? $request->boolean('is_active') : true];
    }
}
