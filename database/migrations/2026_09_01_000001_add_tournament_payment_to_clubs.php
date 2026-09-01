<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Онлайн-оплата участия в турнире — по клубу и по умолчанию выключена.
 *
 * Клуб включает её, только когда у него настроен Plexy и он готов
 * принимать деньги за турниры: остальные продолжают записывать людей
 * через модерацию, как раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('tournament_payment_enabled')
                ->default(false)
                ->after('allow_booking_without_payment');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('tournament_payment_enabled');
        });
    }
};
