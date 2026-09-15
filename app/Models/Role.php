<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    protected $fillable = ['name', 'slug', 'description'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function permissions()
    {
        return $this->hasMany(RolePermission::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->slug === self::SUPER_ADMIN;
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->isSuperAdmin()
            ? Permissions::all()
            : $this->permissions->pluck('permission')->values()->all();
    }

    public function grants(string $permission): bool
    {
        return $this->isSuperAdmin() || in_array($permission, $this->permissionKeys(), true);
    }

    /** @param  list<string>  $permissions  Unknown keys are ignored. */
    public function syncPermissions(array $permissions): void
    {
        $wanted = array_values(array_intersect(Permissions::all(), $permissions));

        $this->permissions()->whereNotIn('permission', $wanted)->delete();

        $existing = $this->permissions()->pluck('permission')->all();

        foreach (array_diff($wanted, $existing) as $permission) {
            $this->permissions()->create(['permission' => $permission]);
        }

        $this->unsetRelation('permissions');
    }
}
