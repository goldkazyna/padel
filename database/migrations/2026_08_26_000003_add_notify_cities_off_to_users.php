<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Города, от которых пользователь не хочет уведомлений.
 * Чёрный список: пусто — приходит всё, как и раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'notify_cities_off')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('notify_cities_off')->nullable()->after('notify_club_ids');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'notify_cities_off')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_cities_off');
        });
    }
};
