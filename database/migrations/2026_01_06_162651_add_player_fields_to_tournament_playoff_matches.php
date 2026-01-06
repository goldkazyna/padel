<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_playoff_matches', function (Blueprint $table) {
            $table->foreignId('team1_player1_id')->nullable()->after('team2_id')->constrained('users')->nullOnDelete();
            $table->foreignId('team1_player2_id')->nullable()->after('team1_player1_id')->constrained('users')->nullOnDelete();
            $table->foreignId('team2_player1_id')->nullable()->after('team1_player2_id')->constrained('users')->nullOnDelete();
            $table->foreignId('team2_player2_id')->nullable()->after('team2_player1_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_playoff_matches', function (Blueprint $table) {
            $table->dropForeign(['team1_player1_id']);
            $table->dropForeign(['team1_player2_id']);
            $table->dropForeign(['team2_player1_id']);
            $table->dropForeign(['team2_player2_id']);
            $table->dropColumn(['team1_player1_id', 'team1_player2_id', 'team2_player1_id', 'team2_player2_id']);
        });
    }
};