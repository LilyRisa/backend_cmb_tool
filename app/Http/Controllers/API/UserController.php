<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\LoginLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function login(Request $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        LoginLog::record($user->id, LoginLog::ACTION_LOGIN, $request->ip(), $request->userAgent(), 'api');

        return response()->json([
            'token' => $token,
            'token_version' => $user->token_version,
            'email_verified' => $user->hasVerifiedEmail(),
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
        ]);

        $referrer = null;
        if ($request->filled('ref')) {
            $referrer = User::where('referral_code', $request->ref)->first();
        }

        $monthlyCredits = SystemSetting::getPremiumMonthlyCredits();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'token_version' => 1,
            'package_type' => 'premium',
            'package_expires_at' => now()->addMonth(),
            'monthly_credits' => $monthlyCredits,
            'purchased_credits' => 0,
            'credits' => $monthlyCredits,
            'credits_reset_at' => now(),
            'referred_by' => $referrer?->id,
        ]);

        CreditTransaction::create([
            'user_id' => $user->id,
            'type' => 'bonus',
            'amount' => $monthlyCredits,
            'balance_after' => $monthlyCredits,
            'description' => "Welcome bonus - 1 tháng Premium miễn phí ({$monthlyCredits} monthly credits)",
            'reference_type' => 'registration',
        ]);

        if ($referrer) {
            $referrer->addCredits(
                800,
                CreditTransaction::TYPE_REFERRAL,
                "Giới thiệu thành công: {$user->name} ({$user->email})",
                User::class,
                $user->id,
                'purchased'
            );
        }

        $token = $user->createToken('mobile')->plainTextToken;

        LoginLog::record($user->id, LoginLog::ACTION_REGISTER, $request->ip(), $request->userAgent(), 'api');

        $charsPerMinute = max(SystemSetting::getCharsPerMinute(), 1);

        return response()->json([
            'message' => 'Register success',
            'token' => $token,
            'user' => $user,
            'email_verified' => false,
            'minutes_remaining' => CreditService::creditsToMinutes($user->credits, $charsPerMinute),
        ]);
    }
}
