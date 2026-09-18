<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Brand extends Model
{
    protected $fillable = ['name', 'slug', 'logo', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Brand $brand) {
            if (blank($brand->slug)) {
                $base = Str::slug($brand->name) ?: 'brand';
                $slug = $base;
                $i = 2;

                while (static::where('slug', $slug)->when($brand->id, fn ($q) => $q->whereKeyNot($brand->id))->exists()) {
                    $slug = $base . '-' . $i++;
                }

                $brand->slug = $slug;
            }
        });
    }

    /** Brands the storefront may show. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Public URL of the logo, or null when the brand has none yet.
     *
     * Built with asset(), like every other upload in this project: it resolves
     * against the address the site is actually being served from, while
     * Storage::url() would pin it to APP_URL and break under a subfolder.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
