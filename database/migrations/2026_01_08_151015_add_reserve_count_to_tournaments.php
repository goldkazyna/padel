<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->integer('reserve_count')->default(0)->after('max_participants');
        });
        
        // Добавляем статус reserve в tournament_participants
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->dropColumn('status');
        });
        
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->enum('status', ['pending', 'registered', 'confirmed', 'cancelled', 'reserve'])->default('pending')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('reserve_count');
        });
        
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->dropColumn('status');
        });
        
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->enum('status', ['pending', 'registered', 'confirmed', 'cancelled'])->default('pending')->after('user_id');
        });
    }
};