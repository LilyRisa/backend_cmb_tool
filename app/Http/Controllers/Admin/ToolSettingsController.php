<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class ToolSettingsController extends Controller
{
    public function index()
    {
        $settings = [
            'image_gen_base_url' => SystemSetting::getImageGenBaseUrl(),
            'image_gen_model' => SystemSetting::getImageGenModel(),
            'image_gen_credits_per_image' => SystemSetting::getImageGenCreditsPerImage(),
            'image_gen_api_key' => SystemSetting::getImageGenApiKey(),
            'genmax_api_key' => SystemSetting::getGenMaxApiKey(),
            'ai_text_base_url' => SystemSetting::getAiTextBaseUrl(),
            'ai_text_model' => SystemSetting::getAiTextModel(),
            'ai_text_api_key' => SystemSetting::getAiTextApiKey(),
        ];

        return view('admin.tool-settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'image_gen_base_url' => 'required|url',
            'image_gen_model' => 'required|string|max:100',
            'image_gen_credits_per_image' => 'required|integer|min:1',
            'image_gen_api_key' => 'nullable|string|max:500',
            'genmax_api_key' => 'nullable|string|max:500',
            'ai_text_base_url' => 'required|url',
            'ai_text_model' => 'required|string|max:100',
            'ai_text_api_key' => 'nullable|string|max:500',
        ]);

        SystemSetting::setImageGenBaseUrl($request->input('image_gen_base_url'));
        SystemSetting::setImageGenModel($request->input('image_gen_model'));
        SystemSetting::setImageGenCreditsPerImage((int) $request->input('image_gen_credits_per_image'));

        if ($request->filled('image_gen_api_key')) {
            SystemSetting::setImageGenApiKey($request->input('image_gen_api_key'));
        }

        if ($request->filled('genmax_api_key')) {
            SystemSetting::setGenMaxApiKey($request->input('genmax_api_key'));
        }

        SystemSetting::setAiTextBaseUrl($request->input('ai_text_base_url'));
        SystemSetting::setAiTextModel($request->input('ai_text_model'));

        if ($request->filled('ai_text_api_key')) {
            SystemSetting::setAiTextApiKey($request->input('ai_text_api_key'));
        }

        return redirect()->route('admin.tool-settings.index')->with('success', 'Đã lưu cấu hình.');
    }
}
