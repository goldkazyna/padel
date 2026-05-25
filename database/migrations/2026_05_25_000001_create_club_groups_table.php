<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('price_per_session', 10, 2)->default(0);
            $table->unsignedInteger('capacity')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_groups');
    }
};
