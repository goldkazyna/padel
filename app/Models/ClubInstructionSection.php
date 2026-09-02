<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Раздел должностных инструкций клуба: «Открытие смены», «Брони», «Турниры». */
class ClubInstructionSection extends Model
{
    protected $fillable = ['club_id', 'title', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function instructions()
    {
        return $this->hasMany(ClubInstruction::class, 'section_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
