<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FeatureCreditUsage;
use App\Services\CreditService;
use Illuminate\Http\Request;

class ToolFeatureCreditController extends Controller
{
    public function deductFeature(Request $request)
    {
        $request->validate([
            'feature' => 'required|string|max:50',
            'duration_seconds' => 'required|integer|min:1',
        ]);

        $feature = $request->input('feature');
        $durationSeconds = (int) $request->input('duration_seconds');
        $user = $request->user();

        try {
            $calculation = CreditService::calculateFeatureCredits($feature, $durationSeconds);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($calculation === null) {
            return response()->json([
                'error' => "Unknown feature: {$feature}",
                'available_features' => array_keys(CreditService::FEATURE_PRICING),
            ], 422);
        }

        $totalCredits = ($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0);
        if ($totalCredits < $calculation['credits']) {
            return response()->json([
                'error' => 'Insufficient credits.',
                'credits_required' => $calculation['credits'],
                'credits_available' => $totalCredits,
            ], 402);
        }

        $usage = FeatureCreditUsage::create([
            'user_id' => $user->id,
            'feature' => $feature,
            'duration_seconds' => $durationSeconds,
            'credits' => $calculation['credits'],
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ]);

        return response()->json([
            'id' => $usage->id,
            'feature' => $usage->feature,
            'duration_seconds' => $usage->duration_seconds,
            'credits' => $usage->credits,
            'status' => $usage->status,
        ], 201);
    }

    public function confirmFeature(Request $request, int $id)
    {
        $request->validate(['status' => 'required|string|in:completed,failed']);

        $user = $request->user();
        $newStatus = $request->input('status');

        $usage = FeatureCreditUsage::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', FeatureCreditUsage::STATUS_PENDING)
            ->first();

        if (!$usage) {
            return response()->json(['error' => 'Pending usage record not found or already processed.'], 404);
        }

        if ($newStatus === FeatureCreditUsage::STATUS_COMPLETED) {
            $deducted = $user->deductCredits(
                $usage->credits,
                "Feature: {$usage->feature} ({$usage->duration_seconds}s)",
                'feature_credit_usage',
                $usage->id
            );

            if (!$deducted) {
                return response()->json([
                    'error' => 'Insufficient credits. Cannot complete deduction.',
                    'credits_required' => $usage->credits,
                    'credits_available' => ($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0),
                ], 402);
            }

            $usage->update(['status' => FeatureCreditUsage::STATUS_COMPLETED]);
        } else {
            $usage->update(['status' => FeatureCreditUsage::STATUS_FAILED]);
        }

        $totalCredits = ($user->fresh()->monthly_credits ?? 0) + ($user->fresh()->purchased_credits ?? 0);

        return response()->json([
            'id' => $usage->id,
            'feature' => $usage->feature,
            'credits' => $usage->credits,
            'status' => $usage->status,
            'credits_deducted' => $newStatus === 'completed',
            'balance' => $totalCredits,
        ]);
    }

    public function featurePricing()
    {
        return response()->json(['pricing' => CreditService::getFeaturePricing()]);
    }
}
