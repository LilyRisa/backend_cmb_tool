<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'prompt' => 'required|string|max:2000',
            'size'   => 'nullable|string|in:256x256,512x512,1024x1024',
            'n'      => 'nullable|integer|min:1|max:4',
        ];
    }

    public function messages(): array
    {
        return [
            'prompt.required' => 'Mô tả ảnh là bắt buộc (prompt is required).',
            'prompt.max'      => 'Mô tả ảnh tối đa 2000 ký tự (prompt max 2000 characters).',
            'size.in'         => 'Kích thước không hợp lệ (invalid size — must be 256x256, 512x512, or 1024x1024).',
            'n.integer'       => 'Số lượng ảnh phải là số nguyên (n must be an integer).',
            'n.min'           => 'Số lượng ảnh tối thiểu là 1 (n minimum is 1).',
            'n.max'           => 'Số lượng ảnh tối đa là 4 (n maximum is 4).',
        ];
    }
}
