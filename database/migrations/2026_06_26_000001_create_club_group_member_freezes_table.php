<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_group_member_freezes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_member_id')->constrained('club_group_members')->cascadeOnDelete();
            $table->date('freeze_from');
            $table->date('freeze_until');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_member_id', 'freeze_from', 'freeze_until'], 'cgmf_member_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_group_member_freezes');
    }
};
