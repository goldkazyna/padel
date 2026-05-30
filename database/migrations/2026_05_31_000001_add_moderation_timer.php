<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedInteger('moderation_hours')->nullable()->after('waitlist_size');
        });
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->timestamp('moderation_deadline')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
        });
        Schema::table('tournament_teams', function (Blueprint $table) {
            $table->timestamp('moderation_deadline')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', fn (Blueprint $t) => $t->dropColumn('moderation_hours'));
        Schema::table('tournament_participants', fn (Blueprint $t) => $t->dropColumn(['moderation_deadline', 'reminder_sent_at']));
        Schema::table('tournament_teams', fn (Blueprint $t) => $t->dropColumn(['moderation_deadline', 'reminder_sent_at']));
    }
};
