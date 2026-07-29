<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class CheckTokenVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        // $token->tokenable and $user are the exact same object instance for
        // the duration of this request (Sanctum's Guard resolves the user by
        // loading $token->tokenable and returns that same instance), so
        // comparing $token->tokenable->token_version against $user->token_version
        // would always be equal — it's the same in-memory row twice. The
        // meaningful comparison is against the version stamped onto the token
        // itself at mint time (see User::createToken), which reflects whatever
        // the user's token_version was back when this specific token was
        // issued. $token may be a Sanctum TransientToken (first-party SPA
        // cookie auth) which carries no version to check, so we only enforce
        // this for real personal access tokens.
        if ($token instanceof PersonalAccessToken && (int) $token->token_version !== (int) $user->token_version) {
            return response()->json(['error' => 'Token expired'], 401);
        }

        return $next($request);
    }
}
