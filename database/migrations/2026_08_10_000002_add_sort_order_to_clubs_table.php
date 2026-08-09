<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            // Ручной порядок в списках приложения: задаёт супер-админ.
            // Пусто — клуб идёт после упорядоченных, по дате добавления.
            $table->unsignedSmallInteger('sort_order')->nullable()->after('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
