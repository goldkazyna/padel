<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Сколько очков разыгрывается в коротком матче (не «до», а ровно столько).
            $table->unsignedSmallInteger('escalera_match_points')->nullable();
            // Режим итоговой таблицы турнира: по сумме баллов за позиции (родной
            // для формата зачёт) либо по сумме очков за все короткие матчи.
            // Ранжирование внутри корта режима не имеет — оно всегда по очкам.
            $table->enum('escalera_standings_mode', ['points', 'raw_points'])->default('points');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['escalera_match_points', 'escalera_standings_mode']);
        });
    }
};
