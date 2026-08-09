<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_attempts', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'exam_id']);
            $table->unique(['student_id', 'exam_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        $hasRetakes = DB::table('student_attempts')
            ->select('student_id', 'exam_id')
            ->groupBy('student_id', 'exam_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasRetakes) {
            throw new RuntimeException('Cannot restore the one-attempt constraint while retake rows exist.');
        }

        Schema::table('student_attempts', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'exam_id', 'attempt_number']);
            $table->unique(['student_id', 'exam_id']);
        });
    }
};
