<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Lowercased and whitespace-collapsed form of the name, which is
            // what the unique index compares: without it "Budi" and "budi "
            // both land in the list and the filter splits one person in two.
            $table->string('name_normalized');
            // Where the requester asks from — a department, a client company.
            // Kept apart from the name so the name stays a name.
            $table->string('organization')->nullable();
            $table->string('email')->nullable();
            // Retires a requester without touching the tasks that name them:
            // an inactive one disappears from the picker and stays in history.
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'name_normalized']);
            $table->index(['workspace_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requesters');
    }
};
