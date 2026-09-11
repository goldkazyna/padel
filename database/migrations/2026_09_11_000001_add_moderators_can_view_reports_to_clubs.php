<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пускать ли менеджеров клуба в отчёты.
 *
 * Отчёты были только у владельца: в них выручка, долги и зарплата тренеров,
 * и открывать их всем подряд клуб не хотел. Но у кого-то менеджер и есть тот,
 * кто считает загрузку кортов, — решает сам клуб, выключателем в настройках.
 * По умолчанию закрыто: как было.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('moderators_can_view_reports')
                ->default(false)
                ->after('auto_conduct_group_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('moderators_can_view_reports');
        });
    }
};
