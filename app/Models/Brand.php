<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Brand extends Model
{
    protected $fillable = ['name', 'slug', 'is_active'];

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

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
