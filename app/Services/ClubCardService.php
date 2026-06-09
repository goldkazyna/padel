<?php

namespace App\Services;

use App\Models\ClubCard;
use Illuminate\Support\Str;

class ClubCardService
{
    /**
     * Сгенерировать уникальный код карты (8 символов, без похожих 0/O/1/I).
     */
    public function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (ClubCard::where('code', $code)->exists());

        return $code;
    }
}
