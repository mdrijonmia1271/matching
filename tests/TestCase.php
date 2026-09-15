<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** An active staff account with the given role slug (roles are seeded by migration). */
    protected function staff(string $role = Role::SUPER_ADMIN, array $attributes = []): User
    {
        $user = new User(array_merge([
            'name' => 'Staff ' . Str::random(4),
            'email' => Str::lower(Str::random(10)) . '@example.com',
            'password' => 'password123',
        ], $attributes));

        $user->forceFill([
            'is_admin' => true,
            'is_active' => true,
            'role_id' => Role::where('slug', $role)->valueOrFail('id'),
        ])->save();

        return $user;
    }
}
