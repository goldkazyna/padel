<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_group_players', function (Blueprint $table) {
            $table->integer('rating_before')->nullable()->after('total_points');
            $table->integer('rating_after')->nullable()->after('rating_before');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_group_players', function (Blueprint $table) {
            $table->dropColumn(['rating_before', 'rating_after']);
        });
    }
};