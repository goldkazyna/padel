<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Just Padel It: ранжировать таблицу по победам (иначе по очкам).
            $table->boolean('jpi_rank_by_wins')->default(false)->after('prizes');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('jpi_rank_by_wins');
        });
    }
};
