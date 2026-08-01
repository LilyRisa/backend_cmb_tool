<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateCheckController extends Controller
{
    public function getCmbLatestVersion(Request $request): JsonResponse
    {
        $request->validate(['type' => 'required|string|max:50']);

        $tool = Tool::where('type', $request->input('type'))
            ->where('is_active', true)
            ->where('is_latest', true)
            ->first();

        if (!$tool) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy phiên bản mới nhất'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->format($tool)]);
    }

    public function getCmbVersionList(Request $request): JsonResponse
    {
        $request->validate(['type' => 'required|string|max:50']);

        $tools = Tool::where('type', $request->input('type'))
            ->where('is_active', true)
            ->orderByDesc('released_at')
            ->get();

        return response()->json(['success' => true, 'data' => $tools->map(fn ($t) => $this->format($t))->values()]);
    }

    private function format(Tool $tool): array
    {
        return [
            'name' => $tool->name,
            'version' => $tool->version,
            'download_url' => $tool->download_url,
            'file_size' => $tool->file_size,
            'sha256' => $tool->sha256,
            'changelog' => $tool->changelog,
            'released_at' => $tool->released_at?->toIso8601String(),
        ];
    }
}
