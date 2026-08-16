<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Отказ от ответственности: включён ли сбор и текст, который подписывают.
     *
     * Выключенная галочка не стирает ни текст, ни собранные подписи — клуб
     * может приостановить сбор и вернуть его.
     */
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('waiver_enabled')->default(false);
            $table->text('waiver_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['waiver_enabled', 'waiver_text']);
        });
    }
};
