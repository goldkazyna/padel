<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Файл при инструкции: скриншот или PDF. Лежит в public/club_instructions. */
class ClubInstructionFile extends Model
{
    protected $fillable = ['instruction_id', 'path', 'name', 'mime', 'size', 'is_image'];

    protected $casts = ['is_image' => 'boolean', 'size' => 'integer'];

    public function instruction()
    {
        return $this->belongsTo(ClubInstruction::class, 'instruction_id');
    }

    /** «2,4 МБ» — размер, понятный человеку. */
    public function humanSize(): string
    {
        if ($this->size >= 1048576) {
            return number_format($this->size / 1048576, 1, ',', ' ') . ' МБ';
        }

        return max(1, (int) round($this->size / 1024)) . ' КБ';
    }
}
