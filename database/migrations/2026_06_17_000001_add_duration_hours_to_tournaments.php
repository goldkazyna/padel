<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Длительность турнира в часах (1-8). Необязательное поле —
            // если задано, в деталях показываем диапазон времени (начало–конец).
            $table->unsignedTinyInteger('duration_hours')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('duration_hours');
        });
    }
};
