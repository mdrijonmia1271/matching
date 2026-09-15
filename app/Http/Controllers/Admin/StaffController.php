<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffController extends Controller implements HasMiddleware
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

    public function index(Request $request)
    {
        $staff = User::with('role')
            ->where('is_admin', true)
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%' . $request->string('q')->trim() . '%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role_id', $request->integer('role')))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.staff.index', [
            'staff' => $staff,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.staff.form', [
            'member' => new User,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['required', 'exists:roles,id'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $role = Role::findOrFail($data['role_id']);

        if ($error = $this->roleAssignmentError($request->user(), $role)) {
            return back()->withInput()->with('error', $error);
        }

        $member = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);
        $member->forceFill(['is_admin' => true, 'is_active' => true, 'role_id' => $role->id])->save();

        AuditLogger::log('staff', 'created', $member, 'Staff account ' . $member->email . ' created as ' . $role->name,
            new: ['name' => $member->name, 'email' => $member->email, 'role' => $role->name]);

        return redirect()->route('admin.staff.index')->with('success', $member->name . ' can now log in as ' . $role->name . '.');
    }

    public function edit(User $staff)
    {
        abort_unless($staff->is_admin, 404);

        return view('admin.staff.form', [
            'member' => $staff->load('role'),
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $staff)
    {
        abort_unless($staff->is_admin, 404);

        $actor = $request->user();

        // Only a Super Admin may change another Super Admin's account.
        abort_if($staff->role?->isSuperAdmin() && ! $actor->isSuperAdmin(), 403, 'Only a Super Admin can edit a Super Admin account.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($staff->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['required', 'exists:roles,id'],
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $role = Role::findOrFail($data['role_id']);
        $active = $staff->is($actor) ? true : $request->boolean('is_active');
        $roleChanged = $role->id !== $staff->role_id;

        if ($staff->is($actor) && $roleChanged) {
            return back()->withInput()->with('error', 'You cannot change your own role.');
        }

        if ($roleChanged && ($error = $this->roleAssignmentError($actor, $role))) {
            return back()->withInput()->with('error', $error);
        }

        if ($staff->isSuperAdmin() && ($roleChanged || ! $active) && $this->activeSuperAdmins() <= 1) {
            return back()->withInput()->with('error', 'At least one active Super Admin must remain.');
        }

        $oldRole = $staff->role?->name;

        $staff->fill(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null]);
        $staff->forceFill(['role_id' => $role->id, 'is_active' => $active]);

        if (filled($data['password'] ?? null)) {
            $staff->password = $data['password'];
        }

        [$old, $new] = AuditLogger::changes($staff);
        $passwordChanged = $staff->isDirty('password');

        if (! $active) {
            $staff->remember_token = null;
        }

        $staff->save();

        if (! $active) {
            $this->endSessions($staff);
        }

        if ($roleChanged) {
            $old['role'] = $oldRole;
            $new['role'] = $role->name;
        }

        if ($new || $passwordChanged) {
            AuditLogger::log('staff', $roleChanged ? 'permission_changed' : 'updated', $staff,
                'Staff account ' . $staff->email . ' updated' . ($passwordChanged ? ' (password reset)' : ''), $old, $new);
        }

        return redirect()->route('admin.staff.index')->with('success', $staff->name . ' updated.');
    }

    /** Staff are deactivated, never deleted: their name stays on orders, stock and payments. */
    public function destroy(Request $request, User $staff)
    {
        abort_unless($staff->is_admin, 404);

        $actor = $request->user();

        if ($staff->is($actor)) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        abort_if($staff->role?->isSuperAdmin() && ! $actor->isSuperAdmin(), 403, 'Only a Super Admin can deactivate a Super Admin account.');

        if ($staff->isSuperAdmin() && $this->activeSuperAdmins() <= 1) {
            return back()->with('error', 'At least one active Super Admin must remain.');
        }

        $staff->forceFill(['is_active' => false, 'remember_token' => null])->save();
        $this->endSessions($staff);

        AuditLogger::log('staff', 'deactivated', $staff, 'Staff account ' . $staff->email . ' deactivated',
            ['is_active' => true], ['is_active' => false]);

        return back()->with('success', $staff->name . ' has been deactivated and logged out.');
    }

    /** Staff may only hand out roles that carry no more access than their own. */
    protected function roleAssignmentError(User $actor, Role $role): ?string
    {
        if ($actor->isSuperAdmin()) {
            return null;
        }

        abort_if($role->isSuperAdmin(), 403, 'Only a Super Admin can assign the Super Admin role.');

        $beyond = array_filter($role->permissionKeys(), fn (string $permission) => ! $actor->hasPermission($permission));

        return $beyond
            ? 'You cannot assign the ' . $role->name . ' role because it has permissions you do not have: ' . implode(', ', $beyond) . '.'
            : null;
    }

    protected function activeSuperAdmins(): int
    {
        return User::where('is_admin', true)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))
            ->count();
    }

    protected function endSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
