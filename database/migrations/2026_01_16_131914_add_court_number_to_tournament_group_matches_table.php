<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Номер корта у матча группы.
     *
     * Проверка hasColumn нужна для боевой базы: колонку там завели мимо
     * миграций, и без проверки `migrate` падал бы на «duplicate column»,
     * останавливая весь деплой.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tournament_group_matches', 'court_number')) {
            return;
        }

        Schema::table('tournament_group_matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('court_number')->nullable()->after('group_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tournament_group_matches', 'court_number')) {
            return;
        }

        Schema::table('tournament_group_matches', function (Blueprint $table) {
            $table->dropColumn('court_number');
        });
    }
};
