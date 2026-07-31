<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // There is no named "login" route (only "admin.login"), so the default
        // AuthenticationException handling — which redirects to route('login')
        // for requests that don't expect JSON — would throw a RouteNotFoundException
        // and turn an unauthenticated API request into a 500. Short-circuit with a
        // plain 401 JSON response for API/JSON requests before that happens.
        $this->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => 'Unauthenticated.'], 401);
            }
        });
    }

    /**
     * Determine if the exception handler response should be JSON.
     *
     * Same rationale as Authenticate::redirectTo() above: api/* requests that
     * merely forgot an Accept: application/json header (e.g. a multipart file
     * upload posted without postJson()) would otherwise fall through to the
     * default web behavior — a 302 redirect to the previous URL on a
     * ValidationException — instead of the 422 JSON response every other API
     * consumer expects.
     */
    protected function shouldReturnJson($request, Throwable $e)
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
