<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FeatureUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureUsageController extends Controller
{
    public function track(Request $request): JsonResponse
    {
        $request->validate([
            'feature_name' => 'required|string|max:100',
        ]);

        $user = $request->user();

        $usage = FeatureUsage::firstOrCreate(
            ['user_id' => $user->id, 'feature_name' => $request->input('feature_name')],
            ['usage_count' => 0]
        );

        $usage->increment('usage_count');
        $usage->update(['last_used_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => ['feature_name' => $usage->feature_name, 'usage_count' => $usage->usage_count],
        ]);
    }
}
