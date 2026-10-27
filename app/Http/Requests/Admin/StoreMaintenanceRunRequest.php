<?php

namespace App\Http\Requests\Admin;

use App\Enums\MaintenanceTask;
use App\Models\MaintenanceRun;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRunRequest extends FormRequest
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
        $task = $this->enum('task', MaintenanceTask::class);

        return [
            'task' => ['required', Rule::enum(MaintenanceTask::class), function (string $attribute, mixed $value, Closure $fail) use ($task): void {
                $alreadyRunning = $task !== null && MaintenanceRun::query()
                    ->unfinished()
                    ->where('task', $task)
                    ->when($task->needsElection(), fn ($query) => $query->where('election_code', $this->integer('election')))
                    ->exists();

                if ($alreadyRunning) {
                    $fail('Esta tarefa já está na fila ou em execução.');
                }
            }],
            'election' => [
                Rule::requiredIf($task?->needsElection() ?? false),
                'nullable',
                'integer',
                Rule::exists('elections', 'tse_code'),
            ],
        ];
    }
}
