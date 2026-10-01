<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in staff member's own account, reached from the avatar menu.
 * No permission check beyond `admin`: everyone may see and edit themselves.
 * Role and active status stay with Staff, so nobody can raise their own access.
 */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return view('admin.profile.show', ['user' => $request->user()->load('role')]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user->fill($data);
        [$old, $new] = AuditLogger::changes($user);
        $user->save();

        if ($new) {
            AuditLogger::log('staff', 'updated', $user, 'Updated own profile', $old, $new);
        }

        return back()->with('success', 'Profile updated.');
    }

    public function editPassword()
    {
        return view('admin.profile.password');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ]);

        $request->user()->update(['password' => $data['password']]);

        AuditLogger::log('staff', 'updated', $request->user(), 'Changed own password');

        return back()->with('success', 'Password changed.');
    }
}
