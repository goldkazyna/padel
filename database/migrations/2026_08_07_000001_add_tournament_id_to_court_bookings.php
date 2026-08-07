<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('court_bookings', function (Blueprint $table) {
            // Турнир, за которым закреплена бронь. Удаление турнира не удаляет
            // бронь — она лишь перестаёт быть турнирной и сохраняет последнюю цену.
            $table->foreignId('tournament_id')->nullable()->after('booking_type')
                  ->constrained()->nullOnDelete();
            // По этой паре сервис собирает набор броней для деления суммы.
            $table->index(['tournament_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('court_bookings', function (Blueprint $table) {
            $table->dropIndex(['tournament_id', 'date']);
            $table->dropForeign(['tournament_id']);
            $table->dropColumn('tournament_id');
        });
    }
};
