<?php

namespace App\Services\Ai;

use App\Enums\TaskPriority;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskPresenter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a sentence typed by a person into a plan of task operations.
 *
 * The model never touches the database: it reads a description of the project
 * and answers with JSON, which {@see TaskPlanApplier} then validates and
 * executes under the caller's own policies. A wrong guess is therefore a
 * rejected operation, not a wrong write.
 */
class TaskPlanner
{
    /**
     * @param  TextModel  $model  the model the person picked in the chat
     * @param  array<int, array{role: string, text: string}>  $history  earlier turns, oldest first
     * @return array{summary: string, operations: array<int, array<string, mixed>>}
     */
    public function plan(PlanScope $scope, User $user, string $instruction, TextModel $model, array $history = []): array
    {
        $answer = $model->ask($this->prompt($scope, $user, $instruction, $history));

        $plan = json_decode($this->stripFences($answer), true);

        if (! is_array($plan) || ! isset($plan['operations']) || ! is_array($plan['operations'])) {
            throw new RuntimeException('Model tidak mengembalikan rencana yang bisa dibaca.');
        }

        $operations = array_values(array_filter(
            $plan['operations'],
            fn ($operation): bool => is_array($operation)
                && in_array($operation['op'] ?? null, ['create', 'update', 'delete'], true),
        ));

        $summary = is_string($plan['summary'] ?? null) ? $plan['summary'] : '';

        // A plan with nothing in it is a real answer, not a failure: "hi" is a
        // greeting, and asking for something impossible deserves a sentence
        // back rather than an HTTP error. Only an unreadable answer throws.
        return [
            'summary' => $operations === [] && $summary === ''
                ? 'Tidak ada perubahan yang bisa dilakukan dari perintah itu.'
                : $summary,
            'operations' => $operations,
        ];
    }

    /**
     * Everything the model is allowed to know: the project, who may carry a
     * task, who work is requested for, and the tasks it may change.
     */
    /**
     * @param  array<int, array{role: string, text: string}>  $history
     */
    protected function prompt(PlanScope $scope, User $user, string $instruction, array $history = []): string
    {
        $projects = $scope->projects($user);

        $context = json_encode([
            'hari_ini' => now()->toDateString(),
            'project' => $projects
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'key' => $project->key,
                    'name' => $project->name,
                ])
                ->all(),
            'status' => array_column(TaskPresenter::statusOptions(), 'label', 'value'),
            'prioritas' => array_map(
                fn (TaskPriority $priority): string => $priority->label(),
                array_column(TaskPriority::cases(), null, 'value'),
            ),
            // Who may carry a task is per project (TSK-4), so the list is
            // grouped rather than pooled — a page that crosses projects would
            // otherwise invite an assignee onto a project they are not on.
            'anggota_per_project' => $projects
                ->mapWithKeys(fn (Project $project): array => [
                    $project->key => $project->members
                        ->map(fn (User $member): array => ['id' => $member->id, 'nama' => $member->name])
                        ->all(),
                ])
                ->all(),
            'pemohon' => array_map(
                fn (array $requester): array => ['id' => $requester['id'], 'nama' => $requester['name']],
                TaskPresenter::requesterOptions(),
            ),
            'task' => $this->taskContext($scope),
            'max_depth' => Task::MAX_DEPTH,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $transcript = $this->transcript($history);
        $placement = $scope->spansProjects()
            ? "- Percakapan ini mencakup beberapa project. Setiap `create` wajib menyebut `project_id` dari daftar `project`, dan `assignee_id` harus anggota project itu.\n"
            : '';

        return <<<PROMPT
        Kamu asisten pengelola task untuk aplikasi manajemen proyek berbahasa Indonesia.
        Tugasmu: menerjemahkan satu perintah pengguna menjadi rencana perubahan task.

        Jawab HANYA dengan satu objek JSON, tanpa penjelasan dan tanpa blok kode:

        {
          "summary": "ringkasan singkat rencana dalam bahasa Indonesia",
          "operations": [
            {"op": "create", "ref": "a", "project_id": null, "title": "...", "description": null, "parent_task_id": null, "parent_ref": null, "assignee_id": null, "requester_id": null, "status": "todo", "priority": "medium", "start_date": null, "due_date": null},
            {"op": "update", "id": 12, "title": "...", "status": "done"},
            {"op": "delete", "id": 13}
          ]
        }

        Aturan:
        {$placement}- Pakai hanya id yang ada di konteks. Jangan mengarang id anggota, pemohon, atau task.
        - `update` dan `delete` menyebut `id` task yang ada. Untuk `update`, kirim hanya field yang berubah.
        - `delete` ikut menghapus seluruh sub task di bawahnya. Pakai hanya kalau pengguna jelas memintanya.
        - Sub task baru di bawah task yang ada: isi `parent_task_id`. Di bawah task yang baru dibuat pada rencana yang sama: beri `ref` unik pada induknya dan tulis `parent_ref` yang sama pada anaknya.
        - Tanggal berformat YYYY-MM-DD dan dihitung dari `hari_ini`. `due_date` tidak boleh lebih awal dari `start_date`.
        - `status` dan `prioritas` hanya boleh memakai kunci yang ada di konteks.
        - Kalau perintahnya tidak jelas atau tidak bisa dikerjakan, kembalikan `operations` kosong dan jelaskan alasannya di `summary`.

        Konteks proyek:
        {$context}
        {$transcript}
        Perintah pengguna:
        {$instruction}
        PROMPT;
    }

    /**
     * The turns before this one, so a follow up like "ganti yang tadi jadi
     * Senin" has something to point at. The panel sends its own transcript
     * back; nothing about a conversation is kept on the server.
     *
     * @param  array<int, array{role: string, text: string}>  $history
     */
    protected function transcript(array $history): string
    {
        if ($history === []) {
            return '';
        }

        $lines = array_map(
            fn (array $turn): string => ($turn['role'] === 'user' ? 'Pengguna: ' : 'Asisten: ').$turn['text'],
            $history,
        );

        return "\nPercakapan sebelumnya:\n".implode("\n", $lines)."\n";
    }

    /**
     * The project's tasks, trimmed to what a planner needs to name one.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function taskContext(PlanScope $scope): array
    {
        return $scope->tasks()
            ->with('project:id,key')
            ->orderBy('path')
            ->limit((int) config('ai.context_tasks'))
            ->get(['id', 'project_id', 'parent_task_id', 'wbs_number', 'title', 'status', 'priority', 'assignee_id', 'requester_id', 'start_date', 'due_date'])
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'ref' => $task->project->key.'-'.$task->wbs_number,
                'project_id' => $task->project_id,
                'parent_task_id' => $task->parent_task_id,
                'title' => $task->title,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'assignee_id' => $task->assignee_id,
                'requester_id' => $task->requester_id,
                'start_date' => $task->start_date?->toDateString(),
                'due_date' => $task->due_date?->toDateString(),
            ])
            ->all();
    }

    /**
     * Models wrap JSON in ```json fences often enough to be worth undoing.
     */
    protected function stripFences(string $answer): string
    {
        $answer = trim($answer);

        if (! Str::startsWith($answer, '```')) {
            return $answer;
        }

        return trim(Str::of($answer)->after("\n")->beforeLast('```')->toString());
    }
}
