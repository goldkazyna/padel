<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('club_id')->nullable()->constrained()->onDelete('set null');
            $table->dateTime('scheduled_at');
            $table->enum('type', ['rated', 'friendly'])->default('rated');
            $table->decimal('min_level', 4, 2)->default(1.00);
            $table->decimal('max_level', 4, 2)->default(5.75);
            $table->enum('status', ['open', 'ready', 'in_progress', 'completed', 'cancelled'])->default('open');
            $table->json('score')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenges');
    }
};
