<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
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
            'progress' => 'progress',
            'start_date' => 'tanggal mulai',
            'due_date' => 'tanggal selesai',
        ];
    }
}
