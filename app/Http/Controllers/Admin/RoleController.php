<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:staff.view', only: ['index']),
            new Middleware('can:staff.create', only: ['create', 'store']),
            new Middleware('can:staff.edit', only: ['edit', 'update']),
            new Middleware('can:staff.delete', only: ['destroy']),
        ];
    }

    public function index()
    {
        return view('admin.roles.index', [
            'roles' => Role::withCount(['users', 'permissions'])->orderByDesc('is_system')->orderBy('name')->get(),
            'permissionTotal' => count(Permissions::all()),
        ]);
    }

    public function create()
    {
        return view('admin.roles.form', ['role' => new Role, 'groups' => Permissions::groups()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if ($error = $this->escalationError($request->user(), $data['permissions'])) {
            return back()->withInput()->with('error', $error);
        }

        $role = new Role(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $role->slug = $this->uniqueSlug($data['name']);
        $role->save();
        $role->syncPermissions($data['permissions']);

        AuditLogger::log('staff', 'role_created', $role, 'Role ' . $role->name . ' created', new: ['permissions' => $role->permissionKeys()]);

        return redirect()->route('admin.roles.index')->with('success', 'Role ' . $role->name . ' created.');
    }

    public function edit(Role $role)
    {
        return view('admin.roles.form', ['role' => $role->load('permissions'), 'groups' => Permissions::groups()]);
    }

    public function update(Request $request, Role $role)
    {
        $actor = $request->user();
        $data = $this->validated($request, $role);

        abort_if($role->isSuperAdmin() && ! $actor->isSuperAdmin(), 403, 'Only a Super Admin can edit the Super Admin role.');

        $before = $role->permissionKeys();

        if (! $role->isSuperAdmin()) {
            if ($role->id === $actor->role_id && ! $actor->isSuperAdmin()) {
                return back()->withInput()->with('error', 'You cannot change the permissions of your own role.');
            }

            if ($error = $this->escalationError($actor, array_diff($data['permissions'], $before))) {
                return back()->withInput()->with('error', $error);
            }
        }

        $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        [$old, $new] = AuditLogger::changes($role);
        $role->save();

        if (! $role->isSuperAdmin()) {
            $role->syncPermissions($data['permissions']);
        }

        $after = $role->permissionKeys();
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($new || $added || $removed) {
            AuditLogger::log('staff', 'permission_changed', $role, 'Role ' . $role->name . ' updated',
                $old + ($removed ? ['removed_permissions' => $removed] : []),
                $new + ($added ? ['added_permissions' => $added] : []));
        }

        return redirect()->route('admin.roles.index')->with('success', 'Role ' . $role->name . ' updated.');
    }

    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return back()->with('error', 'Built-in roles cannot be deleted. You can change their permissions instead.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'Move the staff in this role to another role first.');
        }

        $role->delete();

        AuditLogger::log('staff', 'role_deleted', null, 'Role ' . $role->name . ' deleted', ['name' => $role->name]);

        return back()->with('success', 'Role deleted.');
    }

    /** @return array{name: string, description: ?string, permissions: list<string>} */
    protected function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $data['permissions'] = array_values($data['permissions'] ?? []);

        return $data;
    }

    /** Nobody may grant a permission they do not hold themselves. */
    protected function escalationError(User $actor, array $granting): ?string
    {
        $beyond = array_values(array_filter($granting, fn (string $permission) => ! $actor->hasPermission($permission)));

        return $beyond ? 'You cannot grant permissions you do not have yourself: ' . implode(', ', $beyond) . '.' : null;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'role';
        $slug = $base;
        $i = 2;

        while (Role::where('slug', $slug)->exists()) {
            $slug = $base . '_' . $i++;
        }

        return $slug;
    }
}
