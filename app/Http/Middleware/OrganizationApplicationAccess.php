<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OrganizationApplicationAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth('web')->check()) {
            abort_unless(config('organizations.public_launch') || config('organizations.applications_open'), 404);

            return redirect()->guest(lroute('login'));
        }

        return $next($request);
    }
}
