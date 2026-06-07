<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Личный турнир принадлежит игроку (creator_id задан, club_id = null).
            // Клубный турнир принадлежит клубу (club_id задан, creator_id = null).
            $table->foreignId('creator_id')->nullable()->after('club_id')
                ->constrained('users')->cascadeOnDelete();
            // Клуб теперь необязателен — у личных турниров клуба нет.
            $table->foreignId('club_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creator_id');
            // club_id обратно not null не возвращаем (могли появиться личные турниры).
        });
    }
};
