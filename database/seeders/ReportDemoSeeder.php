<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\StudentAttempt;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReportDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('ReportDemoSeeder is only available in local or testing environments.');
        }

        $created = DB::transaction(function (): bool {
            // Never adopt existing accounts or reset their passwords, roles or data.
            if (User::whereIn('email', ['pdf.teacher@example.test', 'pdf.student@example.test'])->exists()
                || SchoolClass::where('invitation_code', 'PDFDEMO2026')->exists()) {
                return false;
            }

            $teacher = User::create([
                'name' => 'PDF Demo Teacher',
                'email' => 'pdf.teacher@example.test',
                'password' => 'LocalPdfDemo!2026',
                'role' => 'TEACHER',
            ]);
            $student = new User([
                'name' => 'PDF Demo Student',
                'email' => 'pdf.student@example.test',
                'password' => 'LocalPdfDemo!2026',
                'role' => 'STUDENT',
            ]);
            $student->email_verified_at = now();
            $student->save();

            $class = SchoolClass::create([
                'teacher_id' => $teacher->id,
                'title' => 'PDF Demo Class',
                'description' => 'Local sample data for report and PDF testing.',
                'invitation_code' => 'PDFDEMO2026',
            ]);
            $class->students()->attach($student);
            $exam = $class->exams()->create([
                'title' => 'PDF Demo Exam',
                'duration_minutes' => 30,
                'max_score' => 10,
            ]);
            $question = $exam->questions()->create([
                'text' => 'What is 2 + 2?',
                'type' => 'SINGLE',
                'points' => 10,
                'order' => 1,
            ]);
            $correct = $question->options()->create(['text' => '4', 'is_correct' => true]);
            $question->options()->create(['text' => '5', 'is_correct' => false]);
            $attempt = StudentAttempt::create([
                'student_id' => $student->id,
                'exam_id' => $exam->id,
                'attempt_number' => 1,
                'allowed_duration_minutes' => 30,
                'started_at' => now()->subMinutes(20),
                'finished_at' => now()->subMinutes(10),
                'score_obtained' => 10,
            ]);
            $attempt->answers()->create([
                'question_id' => $question->id,
                'answer_option_id' => $correct->id,
            ]);

            return true;
        });

        $this->command?->info($created
            ? 'Report demo created. Open /admin/class-reports with the documented demo teacher account.'
            : 'Demo identifiers already exist; no data changed.');
    }
}
