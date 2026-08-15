<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_groups', function (Blueprint $table) {
            // Сколько тренер получает за каждого пришедшего клиента.
            // NULL — платим как раньше, по часовой групповой ставке тренера
            // (club_coaches.rate_group). Так старые группы ничего не замечают.
            $table->decimal('coach_price_per_client', 10, 2)
                ->nullable()
                ->after('price_per_session');
        });
    }

    public function down(): void
    {
        Schema::table('club_groups', function (Blueprint $table) {
            $table->dropColumn('coach_price_per_client');
        });
    }
};
