<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenge_players', function (Blueprint $table) {
            $table->boolean('score_confirmed')->default(false)->after('rating_change');
        });
    }

    public function down(): void
    {
        Schema::table('challenge_players', function (Blueprint $table) {
            $table->dropColumn('score_confirmed');
        });
    }
};
