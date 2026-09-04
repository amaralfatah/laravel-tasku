<?php

namespace App\Http\Requests\Task;

use App\Concerns\ScopesValidationToWorkspace;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskUpdateRequest extends FormRequest
{
    use ScopesValidationToWorkspace;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $task = $this->route('task');
        $projectId = $task instanceof Task ? $task->project_id : 0;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            // TSK-4: only people on this project may carry its tasks.
            'assignee_id' => ['sometimes', 'nullable', 'integer', $this->existsAsProjectMember($projectId)],
            // Chosen from the workspace's list, never typed: a retired
            // requester is not offered, so it may not be assigned either.
            'requester_id' => ['sometimes', 'nullable', 'integer', $this->existsAsActiveRequester()],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * TSK-8 against the stored values, since a partial update may send only
     * one of the two dates.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $task = $this->route('task');

                if (! $task instanceof Task) {
                    return;
                }

                $start = $this->has('start_date')
                    ? $this->date('start_date')
                    : $task->start_date;

                $due = $this->has('due_date')
                    ? $this->date('due_date')
                    : $task->due_date;

                if ($start !== null && $due !== null && $due->lt($start)) {
                    $validator->errors()->add(
                        'due_date',
                        'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'judul task',
            'assignee_id' => 'penanggung jawab',
            'requester_id' => 'pemohon',
            'progress' => 'progress',
            'start_date' => 'tanggal mulai',
            'due_date' => 'tanggal selesai',
        ];
    }
}
