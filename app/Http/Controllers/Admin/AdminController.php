<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    public function showLogin()
    {
        if (auth()->check() && auth()->user()->is_admin) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $turnstileError = \App\Services\TurnstileVerificationService::verify(
            $request->input('cf_turnstile_token'),
            $request->ip(),
        );

        if ($turnstileError !== null) {
            return back()->with('error', $turnstileError)->withInput();
        }

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $user = Auth::user();

            if (!$user->is_admin) {
                Auth::logout();
                return back()->with('error', 'Access denied. Admin privileges required.')->withInput();
            }

            $request->session()->regenerate();

            return redirect()->intended(route('admin.dashboard'))->with('success', 'Welcome back, ' . $user->name . '!');
        }

        return back()->with('error', 'Invalid email or password.')->withInput();
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'Logged out successfully.');
    }

    public function dashboard()
    {
        $today = Carbon::today();

        $totalUsers = User::count();
        $premiumUsers = User::currentlyPremium()->count();
        $newUsersToday = User::whereDate('created_at', $today)->count();

        return view('admin.dashboard', compact('totalUsers', 'premiumUsers', 'newUsersToday'));
    }
}
