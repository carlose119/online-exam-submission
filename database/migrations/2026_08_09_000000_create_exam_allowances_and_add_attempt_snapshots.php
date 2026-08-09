<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('additional_attempts')->default(0);
            $table->unsignedInteger('extra_time_minutes')->default(0);
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
        });

        Schema::table('student_attempts', function (Blueprint $table) {
            $table->unsignedInteger('attempt_number')->nullable()->after('exam_id');
            $table->unsignedInteger('allowed_duration_minutes')->nullable()->after('attempt_number');
        });
    }

    public function down(): void
    {
        Schema::table('student_attempts', function (Blueprint $table) {
            $table->dropColumn(['attempt_number', 'allowed_duration_minutes']);
        });

        Schema::dropIfExists('exam_allowances');
    }
};
