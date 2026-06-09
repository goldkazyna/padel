<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_card_types', function (Blueprint $table) {
            $table->integer('price')->nullable()->after('discount_percent');           // стоимость карты, ₸
            $table->date('default_expires_at')->nullable()->after('price');            // фиксированная дата окончания
        });
    }

    public function down(): void
    {
        Schema::table('club_card_types', function (Blueprint $table) {
            $table->dropColumn(['price', 'default_expires_at']);
        });
    }
};
