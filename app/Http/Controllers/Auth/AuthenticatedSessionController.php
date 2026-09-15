<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /** Failed attempts allowed per email + IP before a one-minute lockout. */
    protected const MAX_ATTEMPTS = 5;

    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $request, CartService $cart)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($credentials['email']) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.',
            ]);
        }

        // Merge before login: the guest cart is keyed on the pre-login session id.
        if (! Auth::validate($credentials)) {
            RateLimiter::hit($throttleKey, 60);

            if (($attempted = Auth::getLastAttempted()) && $attempted->is_admin) {
                AuditLogger::log('auth', 'login_failed', $attempted, 'Failed login attempt for staff account ' . $attempted->email);
            }

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $user = Auth::getLastAttempted();

        if ($user->is_active === false) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please contact the store owner.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $cart->mergeGuestCart($user->id);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended($user->isStaff() ? route('admin.dashboard') : route('home'))
            ->with('success', 'Welcome back, ' . $user->name . '!');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'You have been logged out.');
    }
}
