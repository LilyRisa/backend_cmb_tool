<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function authorizeClient(Request $request)
    {
        $request->validate(['client_id' => 'required|string|max:64']);

        $clientId = $request->input('client_id');
        $user = $request->user();

        $client = config("oauth_clients.clients.{$clientId}");
        if (!$client) {
            return response()->json(['error' => 'Invalid client_id'], 400);
        }

        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'error' => 'email_not_verified',
                'message' => 'Vui lòng xác minh email trước khi đăng nhập vào ứng dụng.',
            ], 403);
        }

        DB::table('oauth_codes')->where('user_id', $user->id)->where('client_id', $clientId)->delete();

        $plainCode = Str::random(64);

        DB::table('oauth_codes')->insert([
            'user_id' => $user->id,
            'code' => hash('sha256', $plainCode),
            'client_id' => $clientId,
            'expires_at' => now()->addSeconds(60),
            'created_at' => now(),
        ]);

        return response()->json(['code' => $plainCode]);
    }

    public function callback(Request $request)
    {
        $code = $request->query('code');
        $clientId = $request->query('client');
        $state = $request->query('state');

        if (!$code || !$clientId) {
            return $this->errorPage('Thiếu thông tin xác thực. Vui lòng thử đăng nhập lại.');
        }

        $client = config("oauth_clients.clients.{$clientId}");
        if (!$client) {
            return $this->errorPage('Ứng dụng không hợp lệ.');
        }

        $matched = DB::table('oauth_codes')
            ->where('client_id', $clientId)
            ->where('code', hash('sha256', $code))
            ->where('expires_at', '>', now())
            ->first();

        if (!$matched) {
            DB::table('oauth_codes')->where('expires_at', '<=', now())->delete();
            return $this->errorPage('Mã xác thực không hợp lệ hoặc đã hết hạn. Vui lòng đăng nhập lại.');
        }

        DB::table('oauth_codes')->where('id', $matched->id)->delete();

        $user = User::find($matched->user_id);
        if (!$user || !$user->hasVerifiedEmail()) {
            return $this->errorPage('Không tìm thấy tài khoản hoặc email chưa xác minh.');
        }

        $token = $user->createToken('desktop-' . $clientId)->plainTextToken;

        LoginLog::record($user->id, LoginLog::ACTION_LOGIN, $request->ip(), $request->userAgent(), 'oauth-' . $clientId);

        $redirectUrl = $client['protocol'] . '://' . ltrim($client['callback_path'], '/')
            . '?token=' . urlencode($token)
            . '&token_version=' . $user->token_version;

        if ($state !== null && $state !== '') {
            $redirectUrl .= '&state=' . urlencode($state);
        }

        return redirect()->away($redirectUrl);
    }

    private function errorPage(string $message)
    {
        return response()->view('oauth.error', ['message' => $message], 400);
    }
}
