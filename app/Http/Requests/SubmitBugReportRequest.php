<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitBugReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'description' => 'required|string|max:5000',
            'app_version' => 'nullable|string|max:50',
            'device_info' => 'nullable|string|max:1000',
            'screenshots' => 'nullable|array|max:5',
            'screenshots.*' => 'file|image|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Mô tả lỗi là bắt buộc (description is required).',
            'screenshots.max' => 'Tối đa 5 ảnh chụp màn hình (max 5 screenshots).',
            'screenshots.*.image' => 'File phải là hình ảnh (must be an image).',
            'screenshots.*.max' => 'Ảnh tối đa 5MB (max 5MB per image).',
        ];
    }
}
