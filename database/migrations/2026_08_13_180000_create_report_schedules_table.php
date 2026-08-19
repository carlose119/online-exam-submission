<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->enum('format', ['pdf', 'xlsx']);
            $table->json('filters');
            $table->enum('recurrence', ['daily', 'weekly']);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->time('local_time');
            $table->string('timezone', 64);
            $table->dateTime('next_run_at');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['enabled', 'next_run_at']);
        });
        $valid = "(recurrence = 'daily' AND weekday IS NULL) OR (recurrence = 'weekly' AND weekday IS NOT NULL AND weekday BETWEEN 1 AND 7)";
        if (DB::getDriverName() === 'sqlite') {
            $valid = str_replace(['recurrence', 'weekday'], ['NEW.recurrence', 'NEW.weekday'], $valid);
            DB::statement("CREATE TRIGGER report_schedules_weekday_insert BEFORE INSERT ON report_schedules WHEN NOT ({$valid}) BEGIN SELECT RAISE(ABORT, 'invalid report recurrence'); END");
            DB::statement("CREATE TRIGGER report_schedules_weekday_update BEFORE UPDATE ON report_schedules WHEN NOT ({$valid}) BEGIN SELECT RAISE(ABORT, 'invalid report recurrence'); END");
        } else {
            DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_weekday_check CHECK ({$valid})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
