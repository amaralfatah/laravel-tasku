<?php

namespace App\Console\Commands;

use App\Actions\Notify;
use App\Enums\TaskStatus;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use Illuminate\Console\Command;

/**
 * Daily reminder for tasks due today or already overdue (6.14, `due_soon`).
 *
 * Notify::dueSoon is the one that enforces at most one reminder per task per
 * day, so re-running this command is harmless.
 */
class SendDueSoonNotifications extends Command
{
    protected $signature = 'notifications:due-soon';

    protected $description = 'Notify assignees about tasks due today or overdue';

    public function handle(Notify $notify): int
    {
        $sent = 0;

        // Only the tenant scope comes off, because the console has no active
        // workspace. Dropping every scope would take `SoftDeletes` with it and
        // remind people about tasks they have already deleted.
        Task::withoutGlobalScope(WorkspaceScope::class)
            ->whereNotNull('assignee_id')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', now()->toDateString())
            ->where('status', '!=', TaskStatus::Done)
            ->chunkById(200, function ($tasks) use ($notify, &$sent): void {
                foreach ($tasks as $task) {
                    if ($notify->dueSoon($task)) {
                        $sent++;
                    }
                }
            });

        $this->info("{$sent} notifikasi jatuh tempo dikirim.");

        return self::SUCCESS;
    }
}
