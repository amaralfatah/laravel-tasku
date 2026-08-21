<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->string('name');
            $table->string('type')->default('division');
            $table->string('path')->default('');
            $table->smallInteger('depth')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'parent_id']);
            $table->index(['workspace_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};
