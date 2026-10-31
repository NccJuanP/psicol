<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('changes');
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['incident_id', 'id'], 'idx_activities_timeline');
            $table->foreign('incident_id')->references('id')->on('incidents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_activities');
    }
};
