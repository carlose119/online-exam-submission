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
        Schema::table('meetings', function (Blueprint $table) {
            $table->json('recurrence_rule')->nullable()->after('agenda');
            $table->unsignedBigInteger('parent_id')->nullable()->after('recurrence_rule');

            $table->foreign('parent_id')
                ->references('id')
                ->on('meetings')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['recurrence_rule', 'parent_id']);
        });
    }
};
