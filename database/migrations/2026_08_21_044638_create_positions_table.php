<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->smallInteger('level')->default(1);
            $table->timestamps();

            $table->index(['workspace_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
