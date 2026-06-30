<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebpConverter
{
    /**
     * Конвертировать загруженное изображение в webp и сохранить в public-диск.
     * Возвращает относительный путь (для url('/storage/'.$path)).
     */
    public static function store(UploadedFile $file, string $dir, int $quality = 82): string
    {
        $image = self::createImage($file);

        $relPath = trim($dir, '/') . '/' . Str::uuid()->toString() . '.webp';
        $fullPath = Storage::disk('public')->path($relPath);

        $dirPath = dirname($fullPath);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0775, true);
        }

        imagewebp($image, $fullPath, $quality);
        imagedestroy($image);

        return $relPath;
    }

    /** @return \GdImage */
    private static function createImage(UploadedFile $file)
    {
        $path = $file->getRealPath();
        $mime = (string) $file->getMimeType();

        $image = match (true) {
            str_contains($mime, 'jpeg') => @imagecreatefromjpeg($path),
            str_contains($mime, 'png') => @imagecreatefrompng($path),
            str_contains($mime, 'webp') => @imagecreatefromwebp($path),
            default => false,
        };

        if (!$image) {
            throw new \RuntimeException('Неподдерживаемый формат изображения');
        }

        // PNG с прозрачностью → корректный truecolor для webp
        imagepalettetotruecolor($image);

        return $image;
    }
}
