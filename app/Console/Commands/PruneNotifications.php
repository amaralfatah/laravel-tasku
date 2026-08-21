<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;

/**
 * Clears notifications older than 30 days (NTF-6).
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--days=30}';

    protected $description = 'Delete notifications older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = Notification::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("{$deleted} notifikasi lebih dari {$days} hari dihapus.");

        return self::SUCCESS;
    }
}
