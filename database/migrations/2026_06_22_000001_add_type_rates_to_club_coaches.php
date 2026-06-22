<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_coaches', function (Blueprint $table) {
            // Ставки тренера по типу занятия (₸/час). Nullable — если не задано,
            // используется базовая hourly_rate.
            $table->decimal('rate_group', 12, 2)->nullable()->after('hourly_rate');
            $table->decimal('rate_individual', 12, 2)->nullable()->after('rate_group');
        });
    }

    public function down(): void
    {
        Schema::table('club_coaches', function (Blueprint $table) {
            $table->dropColumn(['rate_group', 'rate_individual']);
        });
    }
};
