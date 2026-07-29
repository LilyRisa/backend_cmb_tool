<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\LoginLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CreditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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

    public function me(Request $request)
    {
        $user = $request->user();

        $avatarUrl = $user->avatar
            ? Storage::disk('public')->url($user->avatar)
            : url('/images/defaultavatar.png');

        $userData = $user->toArray();
        $userData['avatar_url'] = $avatarUrl;

        $packageType = $user->package_type ?? 'free';
        $packageExpiresAt = $user->package_expires_at ?? null;

        $isExpired = false;
        $expiresDate = null;
        if ($packageExpiresAt) {
            $expiresDate = Carbon::parse($packageExpiresAt);
            $isExpired = $expiresDate->isPast();
        }

        if ($isExpired) {
            $userData['package_current'] = 'free';
            $userData['package_last'] = $packageType;
            $userData['package_expired'] = true;
            $userData['package_time_end'] = $expiresDate->format('d/m/Y');
            $userData['package_message'] = 'Gói ' . ucfirst($packageType) . ' của bạn đã hết hạn. Vui lòng gia hạn để tiếp tục sử dụng đầy đủ tính năng.';
        } else {
            $userData['package_current'] = $packageType;
            $userData['package_last'] = $packageType;
            $userData['package_expired'] = false;
            $userData['package_time_end'] = $packageExpiresAt ? Carbon::parse($packageExpiresAt)->format('d/m/Y') : null;
            $userData['package_message'] = null;
        }

        $charsPerMinute = max(SystemSetting::getCharsPerMinute(), 1);
        $totalCredits = ($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0);
        $userData['minutes_remaining'] = CreditService::creditsToMinutes($totalCredits, $charsPerMinute);
        $userData['monthly_credits'] = $user->monthly_credits ?? 0;
        $userData['purchased_credits'] = $user->purchased_credits ?? 0;
        $userData['credits_reset_at'] = $user->credits_reset_at ? Carbon::parse($user->credits_reset_at)->toIso8601String() : null;
        $userData['email_verified'] = $user->hasVerifiedEmail();

        return response()->json($userData);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Đăng xuất thành công'], 200);
    }
}
