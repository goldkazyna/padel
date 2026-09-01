<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Номер WhatsApp клуба — отдельно от телефона.
 *
 * Часто это разные номера: на общий телефон звонят, а переписываются с
 * администратором. Гадать нельзя: кнопка «написать» на номер без WhatsApp
 * ведёт в пустоту.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('whatsapp_phone', 32)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('whatsapp_phone');
        });
    }
};
