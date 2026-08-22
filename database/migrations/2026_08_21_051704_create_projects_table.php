<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_unit_id')->constrained('org_units')->cascadeOnDelete();
            $table->string('name');
            // Jira-style project key: the short prefix people read and type.
            $table->string('key', 10);
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['workspace_id', 'key']);
            $table->index(['workspace_id', 'status']);
            $table->index(['org_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
