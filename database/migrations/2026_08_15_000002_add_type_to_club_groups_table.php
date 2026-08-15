<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_groups', function (Blueprint $table) {
            // Вид группы: абонементная (ходят по пакету занятий) или пробная
            // (человек пришёл разово попробовать и ушёл). Уже созданные группы
            // абонементные — так они и работали до появления поля.
            $table->enum('type', ['subscription', 'trial'])
                ->default('subscription')
                ->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('club_groups', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
