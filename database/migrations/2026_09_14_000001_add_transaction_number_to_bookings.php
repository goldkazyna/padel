<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Номер транзакции у брони.
 *
 * Клубам, которые сверяют выручку с выпиской банка, нужен номер платежа
 * рядом с бронью: без него оплату потом не найти. Требовать его всем подряд
 * нельзя — кто-то берёт наличными, — поэтому включается галочкой в настройках
 * клуба и спрашивается только у оплаченных броней.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('require_transaction_number')
                ->default(false)
                ->after('moderators_can_view_reports');
        });

        Schema::table('court_bookings', function (Blueprint $table) {
            $table->string('transaction_number', 64)->nullable()->after('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('require_transaction_number');
        });

        Schema::table('court_bookings', function (Blueprint $table) {
            $table->dropColumn('transaction_number');
        });
    }
};
