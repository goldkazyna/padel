<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Отдельный журнал записей на турнир: кто записался / кто отписался.
// Только действия самого игрока (не админские). Аватар/телефон берём «живьём»
// по user_id при показе.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_registration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('action', ['registered', 'unregistered']);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tournament_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_registration_logs');
    }
};
