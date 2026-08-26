<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Разборы дня переписки WhatsApp: храним готовый ответ модели, чтобы
 * повторное открытие страницы не стоило нового запроса к Claude.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_analyses')) {
            return;
        }

        Schema::create('whatsapp_analyses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->date('date');
            $table->json('metrics')->nullable();
            $table->json('report')->nullable();
            $table->string('model')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_analyses');
    }
};
