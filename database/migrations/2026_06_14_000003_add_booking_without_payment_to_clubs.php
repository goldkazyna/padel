<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            // Показывать ли в приложении кнопку «Записаться без оплаты».
            // По умолчанию включено.
            $table->boolean('allow_booking_without_payment')->default(true)->after('online_payment_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('allow_booking_without_payment');
        });
    }
};
