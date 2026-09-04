<?php

namespace App\Http\Requests\Task;

use App\Concerns\ScopesValidationToWorkspace;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskStoreRequest extends FormRequest
{
    use ScopesValidationToWorkspace;

    /**
     * Only the title is required; everything else is optional (TSK-1).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'parent_task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')->where('project_id', $projectId),
            ],
            // TSK-4: only people on this project may carry its tasks.
            'assignee_id' => ['nullable', 'integer', $this->existsAsProjectMember($projectId)],
            // Chosen from the workspace's list, never typed: a retired
            // requester is not offered, so it may not be assigned either.
            'requester_id' => ['nullable', 'integer', $this->existsAsActiveRequester()],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            // TSK-8: an end date may never precede the start date.
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'judul task',
            'description' => 'deskripsi',
            'parent_task_id' => 'task induk',
            'assignee_id' => 'penanggung jawab',
            'requester_id' => 'pemohon',
            'status' => 'status',
            'priority' => 'prioritas',
            'progress' => 'progress',
            'start_date' => 'tanggal mulai',
            'due_date' => 'tanggal selesai',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
        ];
    }
}
