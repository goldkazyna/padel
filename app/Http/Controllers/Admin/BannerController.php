<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    public function index()
    {
        $banner = Banner::first();
        return view('admin.banners.index', compact('banner'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'image'        => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'link'         => 'nullable|url|max:1000',
            'remove_image' => 'nullable|boolean',
        ]);

        /** @var Banner $banner */
        $banner = Banner::firstOrCreate([], []);

        $banner->link = $request->input('link');

        // Удалить текущий баннер (если поставлен чекбокс)
        if ($request->boolean('remove_image') && $banner->image_path) {
            $this->deleteBannerFile($banner->image_path);
            $banner->image_path = null;
        }

        // Загрузка нового изображения
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $filename = 'banner-' . time() . '.' . $ext;

            // Удаляем старый файл перед сохранением нового
            if ($banner->image_path) {
                $this->deleteBannerFile($banner->image_path);
            }

            $file->move(public_path('banners'), $filename);
            $banner->image_path = '/banners/' . $filename;
        }

        $banner->save();

        return redirect()->route('admin.banners.index')->with('success', 'Баннер сохранён!');
    }

    /**
     * Удалить локальный файл баннера, если это не внешний URL.
     * Поддерживает оба формата: «/banners/x.jpg» и «x.jpg».
     */
    private function deleteBannerFile(?string $path): void
    {
        if (!$path) return;
        if (preg_match('#^https?://#', $path)) return;
        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'banners/')) {
            $relative = substr($relative, strlen('banners/'));
        }
        $fullPath = public_path('banners/' . $relative);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
