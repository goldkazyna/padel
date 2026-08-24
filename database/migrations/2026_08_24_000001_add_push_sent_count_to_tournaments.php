<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Счётчик разосланных по турниру пушей.
 *
 * Организаторы жали колокольчик по многу раз, и одно и то же объявление
 * прилетало людям снова и снова. Больше двух отправок на турнир не даём,
 * а счётчик показываем рядом с кнопкой.
 *
 * Проверка hasColumn — чтобы миграцию можно было применять повторно, не
 * ломая деплой на базах, которые разошлись с историей миграций.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tournaments', 'push_sent_count')) {
            return;
        }

        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedTinyInteger('push_sent_count')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tournaments', 'push_sent_count')) {
            return;
        }

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('push_sent_count');
        });
    }
};
