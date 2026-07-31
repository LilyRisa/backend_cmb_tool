<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'topic'      => 'required|string|max:500',
            'word_count' => 'nullable|integer|min:10|max:5000',
            'context'    => 'required|string|max:100',
            'language'   => 'required|string|max:50',
            'duration'   => 'nullable|integer|min:5|max:600',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (empty($this->word_count) && empty($this->duration)) {
                $validator->errors()->add(
                    'word_count',
                    'Phải cung cấp word_count hoặc duration (You must provide word_count or duration).'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'topic.required'    => 'Chủ đề là bắt buộc (topic is required).',
            'topic.max'         => 'Chủ đề tối đa 500 ký tự (topic max 500 characters).',
            'word_count.integer' => 'Số từ phải là số nguyên (word_count must be an integer).',
            'word_count.min'    => 'Số từ tối thiểu là 10 (word_count minimum is 10).',
            'word_count.max'    => 'Số từ tối đa là 5000 (word_count maximum is 5000).',
            'context.required'  => 'Ngữ cảnh/giọng điệu là bắt buộc (context/tone is required).',
            'language.required' => 'Ngôn ngữ đầu ra là bắt buộc (language is required).',
            'duration.integer'  => 'Thời lượng phải là số nguyên giây (duration must be integer seconds).',
            'duration.min'      => 'Thời lượng tối thiểu 5 giây (duration minimum 5 seconds).',
            'duration.max'      => 'Thời lượng tối đa 600 giây (duration maximum 600 seconds).',
        ];
    }
}
