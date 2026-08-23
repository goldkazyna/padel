<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Описание типа клубной карты — что она даёт владельцу.
 *
 * Клуб пишет его при создании или редактировании типа, а приложение
 * показывает на экране карты: из названия и номинала не видно, входит ли
 * в карту аренда ракетки, гость, заморозка и прочие условия клуба.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_card_types', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('club_card_types', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
