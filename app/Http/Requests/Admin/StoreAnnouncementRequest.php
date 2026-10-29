<?php

namespace App\Http\Requests\Admin;

use App\Enums\Office;
use App\Services\ResultPlace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:300'],
            'office' => ['nullable', Rule::enum(Office::class)],
            'scope_code' => ['nullable', 'required_with:office', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'body' => 'mensagem',
            'scope_code' => 'local',
        ];
    }

    /**
     * Result whose followers get the notice; everyone without one.
     */
    public function place(): ?ResultPlace
    {
        $office = $this->enum('office', Office::class);

        return $office === null ? null : new ResultPlace($office, $this->string('scope_code')->toString());
    }
}
