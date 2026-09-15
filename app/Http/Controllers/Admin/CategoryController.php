<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CategoryController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index']),
            new Middleware('can:products.create', only: ['create', 'store']),
            new Middleware('can:products.edit', only: ['edit', 'update']),
            new Middleware('can:products.delete', only: ['destroy']),
        ];
    }

    public function index()
    {
        return view('admin.categories.index', [
            'categories' => Category::topLevel()
                ->withCount('products')
                ->with(['children' => fn ($q) => $q->withCount('subcategoryProducts')])
                ->orderBy('sort_order')->orderBy('name')
                ->get(),
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.categories.form', [
            'category' => new Category(['parent_id' => $request->integer('parent') ?: null]),
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['image'] = $this->storeImage($request);

        $category = Category::create($data);

        AuditLogger::log('products', 'category_created', $category, 'Category ' . $category->name . ' created');

        return redirect()->route('admin.categories.index')->with('success', 'Category created.');
    }

    public function edit(Category $category)
    {
        return view('admin.categories.form', [
            'category' => $category,
            'parents' => $this->parentOptions($category),
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $data = $this->validated($request, $category);

        if ($path = $this->storeImage($request)) {
            $this->deleteImage($category->image);
            $data['image'] = $path;
        }

        $category->fill($data);
        [$old, $new] = AuditLogger::changes($category);
        $category->save();

        if ($new) {
            AuditLogger::log('products', 'category_updated', $category, 'Category ' . $category->name . ' updated', $old, $new);
        }

        return redirect()->route('admin.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(Category $category)
    {
        if ($category->children()->exists()) {
            return back()->with('error', 'Delete or move this category\'s subcategories first.');
        }

        // Archived products still point at their category.
        if ($category->products()->withTrashed()->exists() || $category->subcategoryProducts()->withTrashed()->exists()) {
            return back()->with('error', 'Move or delete this category\'s products first.');
        }

        $this->deleteImage($category->image);
        $category->delete();

        AuditLogger::log('products', 'category_deleted', null, 'Category ' . $category->name . ' deleted', $category->only(['id', 'name', 'slug', 'parent_id']));

        return back()->with('success', 'Category deleted.');
    }

    protected function parentOptions(?Category $except = null)
    {
        return Category::topLevel()->when($except, fn ($q) => $q->whereKeyNot($except->id))->orderBy('name')->get(['id', 'name']);
    }

    protected function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('parent_id'),
                function (string $attribute, mixed $value, Closure $fail) use ($category) {
                    if ($category && (int) $value === $category->id) {
                        $fail('A category cannot be its own parent.');
                    } elseif ($category && $value && $category->children()->exists()) {
                        $fail('This category has subcategories, so it must stay a main category.');
                    }
                }],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:150', 'alpha_dash', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:2048'],
        ], [
            'parent_id.exists' => 'Subcategories can only be placed under a main category.',
        ]) + ['is_active' => $request->boolean('is_active'), 'sort_order' => (int) $request->input('sort_order', 0)];
    }

    protected function storeImage(Request $request): ?string
    {
        return $request->hasFile('image')
            ? $request->file('image')->store('categories', 'public')
            : null;
    }

    protected function deleteImage(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
