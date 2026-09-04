<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who asked for the work, as opposed to `created_by`, who filed it.
     *
     * `nullOnDelete` is the safety net rather than the plan: a requester still
     * named by a task cannot be deleted (see RequesterController::destroy),
     * and one who has left is deactivated instead.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('requester_id')
                ->nullable()
                ->after('created_by')
                ->constrained('requesters')
                ->nullOnDelete();

            $table->index(['workspace_id', 'requester_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'requester_id']);
            $table->dropConstrainedForeignId('requester_id');
        });
    }
};
