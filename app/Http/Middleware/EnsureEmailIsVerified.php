<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasVerifiedEmail()) {
            return response()->json([
                'error' => 'Vui lòng xác minh email trước khi sử dụng dịch vụ này.',
                'code' => 'email_not_verified',
            ], 403);
        }

        return $next($request);
    }
}
