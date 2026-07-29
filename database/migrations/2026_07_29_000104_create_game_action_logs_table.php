<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('action');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('game_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_action_logs');
    }
};
