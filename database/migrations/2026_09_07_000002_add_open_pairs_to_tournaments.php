<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Открытые пары: игрок записывается один и становится половиной пары.
 *
 * Второе место в паре теперь может пустовать — отсюда nullable у player2_id.
 * Флаг open_pairs включён у всех новых турниров; действующие доигрывают по
 * старому правилу (пары собирает клуб), чтобы схема не поменялась под теми,
 * кто уже записался.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_teams', function (Blueprint $table) {
            $table->unsignedBigInteger('player2_id')->nullable()->change();
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->boolean('open_pairs')->default(true)->after('pairing_mode');
        });

        DB::table('tournaments')->update(['open_pairs' => false]);
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('open_pairs');
        });

        Schema::table('tournament_teams', function (Blueprint $table) {
            $table->unsignedBigInteger('player2_id')->nullable(false)->change();
        });
    }
};
