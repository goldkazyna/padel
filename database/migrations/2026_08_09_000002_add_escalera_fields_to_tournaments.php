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
            // Как ранжируется четвёрка внутри корта: по сумме очков или по победам.
            $table->enum('escalera_rank_mode', ['points', 'wins'])->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['escalera_match_points', 'escalera_rank_mode']);
        });
    }
};
