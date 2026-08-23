<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Модераторы клуба. Таблица на боевой базе уже создана мимо миграций,
     * поэтому создаём только при отсутствии — иначе `migrate` падает на
     * «table already exists» и обрывает деплой.
     */
    public function up(): void
    {
        if (Schema::hasTable('club_moderators')) {
            return;
        }

        Schema::create('club_moderators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['club_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_moderators');
    }
};
