<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateRequest extends FormRequest
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
            'color' => ['nullable', 'string', 'hex_color', 'size:7'],
            'ballot_name' => ['nullable', 'string', 'max:60'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
            'remove_photo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.hex_color' => 'Informe uma cor no formato #RRGGBB.',
            'color.size' => 'Informe uma cor no formato #RRGGBB.',
            'ballot_name.max' => 'O nome deve ter no máximo :max caracteres.',
            'photo.image' => 'Envie uma imagem.',
            'photo.mimes' => 'Envie uma foto em JPEG, PNG ou WebP.',
            'photo.max' => 'A foto deve ter no máximo 2 MB.',
        ];
    }
}
