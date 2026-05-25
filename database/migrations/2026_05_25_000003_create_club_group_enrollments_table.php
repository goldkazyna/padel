<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_group_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_member_id')->constrained('club_group_members')->cascadeOnDelete();
            $table->unsignedInteger('sessions');
            $table->decimal('amount', 10, 2)->default(0);
            $table->boolean('is_paid')->default(false);
            $table->string('payment_method')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('group_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_group_enrollments');
    }
};
