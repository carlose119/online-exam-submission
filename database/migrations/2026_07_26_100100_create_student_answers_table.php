<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_attempt_id');
            $table->unsignedBigInteger('question_id');
            $table->unsignedBigInteger('answer_option_id');
            $table->timestamps();

            $table->foreign('student_attempt_id')->references('id')->on('student_attempts')->onDelete('cascade');
            $table->foreign('question_id')->references('id')->on('questions')->onDelete('cascade');
            $table->foreign('answer_option_id')->references('id')->on('answer_options')->onDelete('cascade');
            $table->unique(['student_attempt_id', 'question_id', 'answer_option_id'], 'student_answers_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_answers');
    }
};
