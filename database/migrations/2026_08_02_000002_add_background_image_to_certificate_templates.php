<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            // Готовая картинка-фон сертификата (клуб загружает свой дизайн).
            $table->string('background_image_path')->nullable()->after('logo_path');
            // Позиции/стили динамических полей поверх картинки (name/value/number).
            $table->json('layout')->nullable()->after('background_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropColumn(['background_image_path', 'layout']);
        });
    }
};
