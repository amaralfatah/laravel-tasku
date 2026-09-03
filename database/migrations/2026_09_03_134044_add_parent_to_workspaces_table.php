<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one workspace sit under another, which is what makes a holding group
 * expressible: the parent is the holding, its children are the operating
 * companies, and each child keeps its own members, org tree and projects.
 *
 * A workspace with no parent and no children is the ordinary case — a
 * freelancer, a studio, a single company — and nothing about it changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            // Detaching rather than cascading: deleting a holding must never
            // take its operating companies' data with it.
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('workspaces')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
