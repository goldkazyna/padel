<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('notify_tournament_reminders')->default(true);
        });
        Schema::table('tournament_participants', function (Blueprint $t) {
            $t->timestamp('reminded_1d_at')->nullable();
            $t->timestamp('reminded_2h_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notify_tournament_reminders'));
        Schema::table('tournament_participants', fn (Blueprint $t) => $t->dropColumn(['reminded_1d_at', 'reminded_2h_at']));
    }
};
