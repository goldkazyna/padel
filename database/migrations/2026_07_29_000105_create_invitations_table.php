<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('inviter_id')->nullable()->constrained('users')->onDelete('set null');
            $table->morphs('invitable');
            $table->enum('kind', ['game', 'tournament', 'training'])->default('game');
            $table->enum('status', ['pending', 'accepted', 'declined', 'expired', 'cancelled'])->default('pending');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
