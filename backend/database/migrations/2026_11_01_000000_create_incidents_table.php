<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('incident');
            $table->string('title', 150);
            $table->text('description');
            $table->unsignedTinyInteger('priority');
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->dateTime('due_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Each index serves a listing/dashboard query: docs/PLAN.md section 6.
            $table->index(['status', 'due_at'], 'idx_incidents_status_due');
            $table->index(['priority', 'status'], 'idx_incidents_priority_status');
            $table->index(['assigned_to', 'status'], 'idx_incidents_assignee_status');
            $table->index(['created_by', 'status'], 'idx_incidents_creator_status');
            // deleted_at as a suffix: the unfiltered count becomes a covering index scan instead of a full
            // table scan, and ORDER BY created_at, id still uses the index (D-38, docs/performance.md).
            $table->index(['created_at', 'deleted_at'], 'idx_incidents_created_at');
            $table->index('due_at', 'idx_incidents_due_at');
            $table->index('resolved_at', 'idx_incidents_resolved_at');
            $table->fullText(['title', 'description'], 'ft_incidents_search');

            // Declared after the composite indexes so MySQL reuses them instead of adding one per FK.
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
