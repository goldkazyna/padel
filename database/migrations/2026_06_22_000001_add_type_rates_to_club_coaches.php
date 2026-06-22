<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_coaches', function (Blueprint $table) {
            // Ставка тренера за групповое занятие (₸/час). Nullable — если не
            // задано, используется базовая hourly_rate. Индивидуальное занятие
            // считается по базовой ставке (отдельное поле не нужно).
            $table->decimal('rate_group', 12, 2)->nullable()->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('club_coaches', function (Blueprint $table) {
            $table->dropColumn('rate_group');
        });
    }
};
