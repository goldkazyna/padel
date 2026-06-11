<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('court_bookings', function (Blueprint $table) {
            // Цена тренера за эту бронь (редактируемая; по умолчанию — ставка тренера).
            $table->decimal('coach_price', 10, 2)->nullable()->after('coach_paid');
        });
    }

    public function down(): void
    {
        Schema::table('court_bookings', function (Blueprint $table) {
            $table->dropColumn('coach_price');
        });
    }
};
