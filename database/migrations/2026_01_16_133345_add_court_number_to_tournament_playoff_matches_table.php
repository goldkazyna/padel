<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Номер корта у матча плей-офф. Проверка hasColumn — по той же причине,
     * что и у матчей группы: на боевой базе колонка уже есть.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tournament_playoff_matches', 'court_number')) {
            return;
        }

        Schema::table('tournament_playoff_matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('court_number')->nullable()->after('tournament_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tournament_playoff_matches', 'court_number')) {
            return;
        }

        Schema::table('tournament_playoff_matches', function (Blueprint $table) {
            $table->dropColumn('court_number');
        });
    }
};
