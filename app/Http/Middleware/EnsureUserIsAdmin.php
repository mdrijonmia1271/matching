<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only active staff accounts with a role may open the admin panel. What they
 * may do inside it is checked per action with `can:<permission>` middleware.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isStaff(), 403, 'Your account does not have access to the admin panel.');

        return $next($request);
    }
}
