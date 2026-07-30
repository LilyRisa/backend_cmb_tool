<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PendingCreditTopup;
use App\Services\SePayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CreditTopupController extends Controller
{
    public function packages()
    {
        $packages = collect(config('credit_packages'))->map(function ($p) {
            return [
                'id' => $p['id'],
                'name' => $p['name'],
                'credits' => $p['credits'],
                'price' => $p['price'],
                'price_per_credit' => round($p['price'] / $p['credits'], 2),
            ];
        });

        return response()->json(['packages' => $packages]);
    }

    public function createTopup(Request $request)
    {
        $request->validate(['package_id' => 'required|string']);

        $package = collect(config('credit_packages'))->firstWhere('id', $request->package_id);

        if (!$package) {
            return response()->json(['error' => 'Gói không tồn tại'], 422);
        }

        if (!SePayService::hasBankConfig()) {
            Log::error('SePay bank config missing');
            return response()->json(['error' => 'Chưa cấu hình thanh toán. Vui lòng liên hệ admin.'], 500);
        }

        $user = $request->user();
        $transactionCode = SePayService::generateTransactionCode($user->id);

        $topup = PendingCreditTopup::create([
            'user_id' => $user->id,
            'package_id' => $package['id'],
            'credits' => $package['credits'],
            'amount' => $package['price'],
            'transaction_code' => $transactionCode,
            'status' => PendingCreditTopup::STATUS_PENDING,
        ]);

        return response()->json([
            'topup_id' => $topup->id,
            'package' => $package,
            'transaction_code' => $transactionCode,
            'bank_info' => SePayService::bankInfo((int) $package['price'], $transactionCode),
            'qr_url' => SePayService::qrUrl((int) $package['price'], $transactionCode),
        ]);
    }

    public function topupStatus(Request $request, int $id)
    {
        $topup = PendingCreditTopup::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$topup) {
            return response()->json(['error' => 'Không tìm thấy giao dịch'], 404);
        }

        return response()->json([
            'id' => $topup->id,
            'status' => $topup->status,
            'package_id' => $topup->package_id,
            'credits' => $topup->credits,
            'amount' => $topup->amount,
            'transaction_code' => $topup->transaction_code,
            'completed_at' => $topup->completed_at?->toIso8601String(),
            'created_at' => $topup->created_at->toIso8601String(),
        ]);
    }
}
