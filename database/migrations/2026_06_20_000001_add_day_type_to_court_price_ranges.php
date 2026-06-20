<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Раздельные цены по будням и выходным. Существующие интервалы становятся
// 'weekday' (базовые) — поведение не меняется. Выходные ('weekend') опциональны:
// если для времени нет выходного интервала — берётся будний (фолбэк в сервисе).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('court_price_ranges', function (Blueprint $table) {
            $table->enum('day_type', ['weekday', 'weekend'])
                ->default('weekday')
                ->after('court_id');
        });
    }

    public function down(): void
    {
        Schema::table('court_price_ranges', function (Blueprint $table) {
            $table->dropColumn('day_type');
        });
    }
};
