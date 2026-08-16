<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubWaiverSignature;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Сохранение подписи под отказом от ответственности.
 *
 * Текст в базу кладётся из копии клуба, а не из запроса: иначе подписать
 * можно было бы что угодно, вплоть до собственной редакции.
 */
class WaiverSignatureService
{
    /** Подпись пальцем весит десятки килобайт; больше — попытка что-то залить. */
    private const MAX_BYTES = 1024 * 1024;

    public function store(
        Club $club,
        User $user,
        string $fullName,
        string $signatureBase64,
        Request $request
    ): ClubWaiverSignature {
        $existing = ClubWaiverSignature::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->first();

        // Двойной тап по кнопке не должен порождать вторую подпись.
        if ($existing) {
            return $existing;
        }

        $png = $this->decode($signatureBase64);

        $signature = ClubWaiverSignature::create([
            'club_id' => $club->id,
            'user_id' => $user->id,
            'full_name' => $fullName,
            'phone' => $user->phone,
            'waiver_text' => (string) $club->waiver_text,
            'signature_path' => 'waivers/pending',
            'signed_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ]);

        // Путь знаем только после вставки: в нём id подписи.
        $path = "waivers/{$club->id}/{$signature->id}.png";
        Storage::disk('local')->put($path, $png);
        $signature->update(['signature_path' => $path]);

        return $signature;
    }

    /**
     * Разобрать картинку подписи.
     *
     * @throws RuntimeException если это не PNG, он пуст или слишком велик
     */
    private function decode(string $value): string
    {
        $value = preg_replace('#^data:image/png;base64,#', '', trim($value));
        $png = base64_decode((string) $value, true);

        if ($png === false || $png === '') {
            throw new RuntimeException('Подпись не распознана');
        }
        if (strlen($png) > self::MAX_BYTES) {
            throw new RuntimeException('Слишком большая картинка подписи');
        }
        if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('Подпись должна быть PNG');
        }
        if ($this->isBlank($png)) {
            throw new RuntimeException('Подпись пустая');
        }

        return $png;
    }

    /** Полностью прозрачная или одноцветная картинка — это не подпись. */
    private function isBlank(string $png): bool
    {
        $img = @imagecreatefromstring($png);
        if (!$img) {
            return true;
        }

        $width = imagesx($img);
        $height = imagesy($img);
        $first = null;

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $color = imagecolorat($img, $x, $y);
                if ($first === null) {
                    $first = $color;
                } elseif ($color !== $first) {
                    imagedestroy($img);

                    return false;
                }
            }
        }
        imagedestroy($img);

        return true;
    }
}
