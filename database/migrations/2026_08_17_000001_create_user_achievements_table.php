<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Прогресс игрока по значкам.
     *
     * target хранится снимком: если порог значка потом поправят, уже выданное
     * достижение и текст старого пуша не разъедутся с тем, что на экране.
     * notified_at отделён от unlocked_at, чтобы заливка истории могла отметить
     * значки как «уведомление отправлено», не отправляя ничего.
     */
    public function up(): void
    {
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->integer('progress')->default(0);
            $table->integer('target');
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'code']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
    }
};
