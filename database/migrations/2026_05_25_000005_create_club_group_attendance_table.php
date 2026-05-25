<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_group_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('club_group_sessions')->cascadeOnDelete();
            $table->foreignId('group_member_id')->constrained('club_group_members')->cascadeOnDelete();
            $table->boolean('attended')->default(false);
            $table->boolean('charged')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'group_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_group_attendance');
    }
};
