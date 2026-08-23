<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Флаг «скрывать телефоны клиентов» у клуба. Проверка hasColumn нужна,
     * чтобы миграция была идемпотентной: базы разъехались, и на части из них
     * колонка уже есть.
     */
    public function up(): void
    {
        if (Schema::hasColumn('clubs', 'hide_phones')) {
            return;
        }

        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('hide_phones')->default(false)->after('is_community');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('clubs', 'hide_phones')) {
            return;
        }

        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('hide_phones');
        });
    }
};
