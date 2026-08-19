<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('class_id');
            $table->dateTime('occurrence_at');
            $table->char('definition_hash', 64);
            $table->enum('status', ['pending', 'completed', 'skipped'])->default('pending');
            $table->string('artifact_path')->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['schedule_id', 'occurrence_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
