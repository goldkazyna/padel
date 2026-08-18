<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Отметка «пришёл» в списке участников турнира.
     *
     * Время, а не флаг: организатору бывает важно, когда человек отметился,
     * а отсутствие отметки — просто пустое поле.
     */
    public function up(): void
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->timestamp('attended_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->dropColumn('attended_at');
        });
    }
};
