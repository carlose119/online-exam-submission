<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('student_attempts')
            ->whereNull('finished_at')
            ->whereNull('allowed_duration_minutes')
            ->chunkById(100, function ($attempts): void {
                $durations = DB::table('exams')
                    ->whereIn('id', $attempts->pluck('exam_id'))
                    ->pluck('duration_minutes', 'id');

                foreach ($attempts as $attempt) {
                    DB::table('student_attempts')
                        ->where('id', $attempt->id)
                        ->whereNull('allowed_duration_minutes')
                        ->update(['allowed_duration_minutes' => $durations[$attempt->exam_id]]);
                }
            });
    }

    public function down(): void
    {
        // Preserve snapshots because their original null state cannot be reconstructed safely.
    }
};
