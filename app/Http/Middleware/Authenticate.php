<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // There is no named "login" route in this app (only "admin.login"), so
        // calling route('login') for API requests that merely forgot an
        // Accept: application/json header would throw a RouteNotFoundException
        // instead of the intended 401. Treat any api/* request as JSON too.
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        return route('login');
    }
}
