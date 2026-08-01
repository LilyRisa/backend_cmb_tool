<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitBugReportRequest;
use App\Models\BugReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BugReportController extends Controller
{
    public function submit(SubmitBugReportRequest $request): JsonResponse
    {
        $user = $request->user();

        $screenshotUrls = [];
        foreach ($request->file('screenshots', []) as $file) {
            $filename = 'bug-reports/' . Str::uuid() . '.' . $file->getClientOriginalExtension();
            Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));
            $screenshotUrls[] = Storage::disk('public')->url($filename);
        }

        $report = BugReport::create([
            'user_id' => $user->id,
            'description' => $request->input('description'),
            'screenshots' => $screenshotUrls ?: null,
            'screenshot_count' => count($screenshotUrls),
            'app_version' => $request->input('app_version'),
            'device_info' => $request->input('device_info'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'data' => ['id' => $report->id],
        ], 201);
    }
}
