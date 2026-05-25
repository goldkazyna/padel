<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('club_groups')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('club_clients')->cascadeOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['group_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_group_members');
    }
};
