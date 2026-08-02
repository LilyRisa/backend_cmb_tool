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

    /**
     * GET /api/cmb/latest — legacy alias of the old ESP32-monolith backend's
     * endpoint of the same name. No `type` param (hardcoded to 'cmb_core'),
     * and a different response shape than getCmbLatestVersion() — kept
     * byte-for-byte compatible with the old contract so cmb_audio_tool_marketing's
     * UpdateService.js (which reads data.direct_url/download_url, data.sha256,
     * data.file_size as a raw byte count) doesn't need further client changes.
     */
    public function getCmbLatestVersionLegacy(): JsonResponse
    {
        $tool = Tool::where('type', 'cmb_core')
            ->where('is_active', true)
            ->where('is_latest', true)
            ->first();

        if (!$tool) {
            return response()->json([
                'success' => false,
                'error' => 'no_version_found',
                'message' => 'No CMB Core Marketing version available',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'version' => $tool->version,
                'download_url' => $tool->download_url,
                'direct_url' => $tool->download_url,
                'release_notes' => $tool->description ?? '',
                'changelog' => $tool->changelog ?? '',
                'release_date' => $tool->released_at?->toIso8601String(),
                'file_size' => $this->parseFileSizeToBytes($tool->file_size),
                'file_size_formatted' => $tool->file_size ?? 'N/A',
                'sha256' => $tool->sha256,
            ],
        ]);
    }

    /**
     * Parse a free-text file-size string ("202 MB", "193.84 MB", or a raw
     * byte count like "2784") into a raw byte count. Mirrors the old
     * backend's helper of the same name so downloadUpdate()'s progress-bar
     * math (which needs a numeric byte count) keeps working.
     */
    private function parseFileSizeToBytes(?string $fileSize): int
    {
        if (!$fileSize) {
            return 0;
        }

        $fileSize = str_replace(' ', '', $fileSize);

        if (preg_match('/^([\d.]+)\s*(B|KB|MB|GB|TB)?$/i', $fileSize, $matches)) {
            $number = (float) $matches[1];
            $unit = strtoupper($matches[2] ?? 'B');

            $multipliers = [
                'B' => 1,
                'KB' => 1024,
                'MB' => 1024 * 1024,
                'GB' => 1024 * 1024 * 1024,
                'TB' => 1024 * 1024 * 1024 * 1024,
            ];

            return (int) round($number * ($multipliers[$unit] ?? 1));
        }

        return 0;
    }
}
