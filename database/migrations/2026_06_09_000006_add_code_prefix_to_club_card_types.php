<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_card_types', function (Blueprint $table) {
            $table->string('code_prefix', 12)->nullable()->after('name');        // префикс номера карты (напр. VIP)
            $table->unsignedInteger('last_card_number')->default(0)->after('code_prefix'); // последний выданный номер серии
        });
    }

    public function down(): void
    {
        Schema::table('club_card_types', function (Blueprint $table) {
            $table->dropColumn(['code_prefix', 'last_card_number']);
        });
    }
};
