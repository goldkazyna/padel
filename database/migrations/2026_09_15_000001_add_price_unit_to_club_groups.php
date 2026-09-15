<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цена группы: за занятие или за час.
 *
 * У групп со смешанным расписанием (час в четверг, два часа в субботу) одна
 * цена за занятие означала, что двухчасовое занятие приносит столько же, а
 * тренеру за него платится вдвое. Теперь клуб выбирает единицу цены сам;
 * по умолчанию — «за занятие», как работало раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_groups', function (Blueprint $table) {
            $table->string('price_unit', 16)->default('session')->after('price_per_session');
        });
    }

    public function down(): void
    {
        Schema::table('club_groups', function (Blueprint $table) {
            $table->dropColumn('price_unit');
        });
    }
};
