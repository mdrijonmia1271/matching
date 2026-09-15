<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control. `users.is_admin` keeps its meaning ("staff account
 * that may open the admin panel"); what a staff member may do comes from the role.
 * Every existing admin becomes a Super Admin, so nobody loses access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 60)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 60);

            $table->unique(['role_id', 'permission']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('is_admin')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('role_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        $now = now();

        foreach (config('permissions.roles') as $slug => $role) {
            $roleId = DB::table('roles')->insertGetId([
                'slug' => $slug,
                'name' => $role['name'],
                'description' => $role['description'],
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $permissions = $role['permissions'] === ['*'] ? [] : $role['permissions'];

            if ($permissions) {
                DB::table('role_permissions')->insert(
                    array_map(fn (string $permission) => ['role_id' => $roleId, 'permission' => $permission], $permissions)
                );
            }
        }

        DB::table('users')->where('is_admin', true)->update([
            'role_id' => DB::table('roles')->where('slug', 'super_admin')->value('id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['is_active', 'last_login_at']);
        });

        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
