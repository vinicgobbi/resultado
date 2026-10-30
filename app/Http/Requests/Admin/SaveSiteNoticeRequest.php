<?php

namespace App\Http\Requests\Admin;

use App\Enums\NoticeLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSiteNoticeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:300'],
            'level' => ['required', Rule::enum(NoticeLevel::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:now', ...($this->filled('starts_at') ? ['after:starts_at'] : [])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Escreva o aviso.',
            'message.max' => 'O aviso deve ter no máximo :max caracteres.',
            'level.required' => 'Escolha o destaque.',
            'ends_at.after' => 'O fim deve ser depois do início e de agora.',
        ];
    }
}
