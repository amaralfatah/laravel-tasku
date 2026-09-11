<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every finished task a closing date.
 *
 * `TaskObserver::saving()` stamps `completed_at` on the transition into a done
 * status, but rows that never went through it carry none: the first backfill
 * covered `done` only and missed `cancelled`, and the seeded and imported work
 * that landed afterwards wrote its status straight into the table.
 *
 * A null there is not harmless. The board orders Selesai and Dibatalkan newest
 * first and folds away what closed over a month ago; a task with no stamp sits
 * at the far end of that order and never folds, so one card closed last year
 * stays on the board above work that closed this morning.
 *
 * `due_date` is the closest thing these rows carry to a closing date — imported
 * work is historical and its target date is the record of when it was meant to
 * be over. It is only trusted up to `updated_at`: a date past the last edit is
 * a deadline the row never reached, and a `completed_at` in the future would
 * sort to the top of the column and never age out. Everything else falls back
 * to `updated_at`, as the original backfill did.
 */
return new class extends Migration
{
    /**
     * Statuses in the Done category — see `App\Enums\TaskStatus::isDone()`.
     *
     * Spelled out rather than read off the enum: a migration runs against the
     * table as it was, and the enum may have moved on by then.
     *
     * @var array<int, string>
     */
    private const FINISHED = ['done', 'cancelled'];

    public function up(): void
    {
        DB::table('tasks')
            ->whereIn('status', self::FINISHED)
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->whereColumn('due_date', '<=', 'updated_at')
            ->update(['completed_at' => DB::raw('due_date')]);

        DB::table('tasks')
            ->whereIn('status', self::FINISHED)
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('updated_at')]);
    }

    /**
     * Irreversible: the stamps this writes are indistinguishable from the ones
     * the observer wrote, so rolling back would clear real closing dates too.
     */
    public function down(): void
    {
        //
    }
};
