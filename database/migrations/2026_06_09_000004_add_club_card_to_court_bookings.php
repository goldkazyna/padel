<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('court_bookings', function (Blueprint $table) {
            // Выбранная клубная карта для этой брони (счётчик списывается кроном
            // после окончания брони; скидочная применяет % сразу).
            $table->foreignId('club_card_id')->nullable()->after('coach_id')
                ->constrained()->nullOnDelete();
            $table->timestamp('card_charged_at')->nullable()->after('club_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('court_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('club_card_id');
            $table->dropColumn('card_charged_at');
        });
    }
};
