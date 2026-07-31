<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateSceneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'script'         => 'required|string|max:20000',
            'context'        => 'required|string|in:thân mật,hài hước,nghiêm túc,truyền cảm hứng,lạc quan,bi quan,nhiệt tình',
            'total_duration' => 'required|integer|min:10|max:1200',
        ];
    }

    public function messages(): array
    {
        return [
            'script.required'         => 'Kịch bản là bắt buộc (script is required).',
            'script.max'              => 'Kịch bản tối đa 20.000 ký tự (script max 20,000 characters).',
            'context.required'        => 'Ngữ cảnh/giọng điệu là bắt buộc (context/tone is required).',
            'context.in'              => 'Ngữ cảnh không hợp lệ (invalid context value).',
            'total_duration.required' => 'Tổng thời lượng video là bắt buộc (total_duration is required).',
            'total_duration.integer'  => 'Thời lượng phải là số nguyên giây (total_duration must be integer seconds).',
            'total_duration.min'      => 'Thời lượng tối thiểu 10 giây (total_duration minimum 10 seconds).',
            'total_duration.max'      => 'Thời lượng tối đa 1200 giây / 20 phút (total_duration maximum 1200 seconds).',
        ];
    }
}
