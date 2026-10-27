<?php

namespace App\Http\Requests;

use App\Enums\Office;
use App\Services\ResultPlace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FollowResultRequest extends FormRequest
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
            'endpoint' => ['required', 'string', 'max:2048'],
            'office' => ['required', Rule::enum(Office::class)],
            'scope_code' => ['required', 'string', 'max:5', function (string $attribute, mixed $value, Closure $fail): void {
                $office = Office::tryFrom((string) $this->input('office'));

                if ($office !== null && ! (new ResultPlace($office, (string) $value))->exists()) {
                    $fail('Este local não tem esse cargo.');
                }
            }],
            'notify_updates' => ['sometimes', 'boolean'],
            'notify_decided' => ['sometimes', 'boolean'],
            'notify_final' => ['sometimes', 'boolean'],
        ];
    }

    public function place(): ResultPlace
    {
        return new ResultPlace($this->enum('office', Office::class) ?? Office::President, $this->string('scope_code')->toString());
    }
}
