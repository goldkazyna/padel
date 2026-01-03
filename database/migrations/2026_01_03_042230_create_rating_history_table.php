<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('tournament_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('rating_before');
            $table->integer('rating_after');
            $table->integer('change');
            $table->string('reason')->nullable(); // Название турнира или причина
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_history');
    }
};