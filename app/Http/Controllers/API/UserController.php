<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationMail;
use App\Mail\ForgotPasswordMail;
use App\Models\CreditTransaction;
use App\Models\LoginLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CreditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function login(Request $request)
    {
        // Verify Cloudflare Turnstile
        $turnstileError = $this->verifyTurnstile($request);
        if ($turnstileError) return $turnstileError;

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

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
        // Verify Cloudflare Turnstile
        $turnstileError = $this->verifyTurnstile($request);
        if ($turnstileError) return $turnstileError;

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

        [$user, $token] = DB::transaction(function () use ($request, $referrer, $monthlyCredits) {
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

            return [$user, $token];
        });

        $charsPerMinute = max(SystemSetting::getCharsPerMinute(), 1);

        $this->sendVerificationEmail($user);

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

    public function verifyEmail(string $token)
    {
        $matched = DB::table('email_verification_tokens')
            ->where('token', hash('sha256', $token))
            ->first();

        if (!$matched || \Carbon\Carbon::parse($matched->expires_at)->isPast()) {
            if ($matched) DB::table('email_verification_tokens')->where('id', $matched->id)->delete();
            return view('auth.email-verified', ['success' => false]);
        }

        $user = User::find($matched->user_id);
        if (!$user) {
            DB::table('email_verification_tokens')->where('id', $matched->id)->delete();
            return view('auth.email-verified', ['success' => false]);
        }

        if (!$user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
            $user->save();
        }

        DB::table('email_verification_tokens')->where('user_id', $matched->user_id)->delete();

        return view('auth.email-verified', ['success' => true]);
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email đã được xác minh.', 'email_verified' => true]);
        }

        $recentToken = DB::table('email_verification_tokens')
            ->where('user_id', $user->id)
            ->where('created_at', '>', now()->subMinutes(2))
            ->exists();

        if ($recentToken) {
            return response()->json(['error' => 'Vui lòng đợi 2 phút trước khi gửi lại.', 'code' => 'rate_limited'], 429);
        }

        $this->sendVerificationEmail($user);

        return response()->json(['message' => 'Email xác minh đã được gửi lại.']);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        $genericMessage = 'Nếu email tồn tại, chúng tôi đã gửi hướng dẫn đặt lại mật khẩu.';

        if (!$user) {
            return response()->json(['message' => $genericMessage], 200);
        }

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        $token = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $resetUrl = url('/password/reset/' . $token . '?email=' . urlencode($user->email));

        try {
            Mail::to($user->email)->send(new ForgotPasswordMail($user, $resetUrl));
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email: ' . $e->getMessage());
        }

        return response()->json(['message' => $genericMessage], 200);
    }

    public function showResetForm(Request $request, string $token)
    {
        $email = $request->query('email', '');

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$record || !Hash::check($token, $record->token) || \Carbon\Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            return view('auth.reset-password', ['expired' => true]);
        }

        return view('auth.reset-password', ['token' => $token, 'email' => $email, 'expired' => false]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json(['error' => 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.'], 422);
        }

        if (\Carbon\Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['error' => 'Liên kết đặt lại mật khẩu đã hết hạn. Vui lòng yêu cầu lại.'], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['error' => 'Không tìm thấy tài khoản.'], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        $user->tokens()->delete();
        $user->increment('token_version');

        return response()->json(['message' => 'Mật khẩu đã được đặt lại thành công. Vui lòng đăng nhập lại.']);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|min:6|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'Mật khẩu hiện tại không đúng.'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        $currentTokenId = $request->user()->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        $user->increment('token_version');

        return response()->json(['message' => 'Đổi mật khẩu thành công!']);
    }

    public function updateName(Request $request)
    {
        $request->validate(['name' => 'required|min:3']);

        $user = $request->user();
        $user->name = $request->name;
        $user->save();

        return response()->json(['message' => 'Cập nhật tên hiển thị thành công!', 'user' => $user->fresh()]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'nullable|string|max:255',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        $updateData = [];

        if ($request->filled('name')) {
            $updateData['name'] = $request->name;
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $filename = $user->id . '_' . time() . '_' . Str::random(6) . '.' . $request->file('avatar')->getClientOriginalExtension();
            $updateData['avatar'] = $request->file('avatar')->storeAs('avatars', $filename, 'public');
        }

        if (!empty($updateData)) {
            $user->update($updateData);
            $user = $user->fresh();
        }

        $avatarUrl = $user->avatar ? Storage::disk('public')->url($user->avatar) : null;

        $userData = $user->toArray();
        $userData['avatar_url'] = $avatarUrl;

        return response()->json(['message' => 'Cập nhật thông tin thành công', 'user' => $userData]);
    }

    private function sendVerificationEmail(User $user): void
    {
        DB::table('email_verification_tokens')->where('user_id', $user->id)->delete();

        $token = Str::random(64);
        DB::table('email_verification_tokens')->insert([
            'user_id' => $user->id,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $verificationUrl = url('/email/verify/' . $token);

        try {
            Mail::to($user->email)->send(new EmailVerificationMail($user, $verificationUrl));
        } catch (\Exception $e) {
            Log::error('Failed to send verification email: ' . $e->getMessage());
        }
    }

    /**
     * Verify Cloudflare Turnstile token.
     * Returns null if valid, or JsonResponse if invalid.
     */
    private function verifyTurnstile(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $error = \App\Services\TurnstileVerificationService::verify(
            $request->input('cf_turnstile_token'),
            $request->ip(),
        );

        if ($error === null) {
            return null;
        }

        $code = $error === 'Vui lòng xác thực captcha' ? 'turnstile_required' : 'turnstile_failed';

        return response()->json(['error' => $error, 'code' => $code], 422);
    }
}
