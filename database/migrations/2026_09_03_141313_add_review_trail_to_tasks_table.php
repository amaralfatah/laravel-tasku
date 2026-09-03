<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records who accepted a piece of work, and when.
 *
 * Finishing a task used to be one person's click. With the `review` status a
 * task is handed up instead: whoever owns the task above it accepts it or
 * sends it back, which is how a graded organisation actually closes work — and
 * with nested tasks it repeats at every level for free.
 *
 * `submitted_at` is when the worker handed it over, `reviewed_at`/`reviewed_by`
 * the decision that followed. All three are null for a task nobody has
 * submitted, which is every task that existed before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestampTz('submitted_at')->nullable()->after('completed_at');
            $table->timestampTz('reviewed_at')->nullable()->after('submitted_at');
            // Detaching rather than cascading: the decision stands even after
            // the account that made it is gone.
            $table->foreignId('reviewed_by')
                ->nullable()
                ->after('reviewed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['submitted_at', 'reviewed_at']);
        });
    }
};
