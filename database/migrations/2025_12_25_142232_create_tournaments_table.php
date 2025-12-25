<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->dateTime('start_date');
            $table->dateTime('registration_deadline');
            $table->decimal('min_level', 3, 2)->default(1.00);
            $table->decimal('max_level', 3, 2)->default(5.75);
            $table->integer('max_participants')->default(16);
            $table->decimal('price', 10, 2)->default(0);
            $table->enum('status', ['draft', 'open', 'closed', 'in_progress', 'completed', 'cancelled'])->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};