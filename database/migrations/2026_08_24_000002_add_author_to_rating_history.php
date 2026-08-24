<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто и от какого клуба поправил рейтинг вручную.
 *
 * В «Динамике рейтинга» такая точка подписывалась просто «Padel Kz» — было
 * не понять, чей администратор менял. У истории уровня (`user_level_history`)
 * эти поля есть давно, здесь повторяем ту же пару.
 *
 * Старые записи останутся с null: кто их сделал, мы уже не узнаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rating_history', 'changed_by_user_id')) {
            return;
        }

        Schema::table('rating_history', function (Blueprint $table) {
            $table->foreignId('changed_by_user_id')->nullable()->after('tournament_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('club_id')->nullable()->after('changed_by_user_id')
                ->constrained('clubs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rating_history', 'changed_by_user_id')) {
            return;
        }

        Schema::table('rating_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('changed_by_user_id');
            $table->dropConstrainedForeignId('club_id');
        });
    }
};
