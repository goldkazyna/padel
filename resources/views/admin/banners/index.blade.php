@extends('layouts.app')

@section('title', 'Рекламный баннер')

@section('content')
<div class="page-header">
    <div>
        <h2>Рекламный баннер</h2>
        <p>Одиночный баннер для главной страницы приложения</p>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success mb-4" style="background: var(--success-bg, #d1fae5); color: var(--success-text, #065f46); border: 1px solid var(--success-border, #6ee7b7); border-radius: 10px; padding: 12px 16px;">
        <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
    </div>
@endif

<div class="row">
    <div class="col-lg-8">
        <div class="card-dark">
            <div class="card-body">
                <form action="{{ route('admin.banners.update') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    {{-- Изображение баннера --}}
                    @php
                        $currentImage = $banner->image_path ?? null;
                        if (!$currentImage) {
                            $bannerUrl = null;
                        } elseif (preg_match('#^https?://#', $currentImage)) {
                            $bannerUrl = $currentImage;
                        } elseif (str_starts_with($currentImage, '/')) {
                            $bannerUrl = url($currentImage);
                        } else {
                            $bannerUrl = asset('banners/' . $currentImage);
                        }
                    @endphp

                    <div class="mb-4">
                        <label class="form-label">Изображение баннера</label>
                        @if($bannerUrl)
                            <div class="mb-3">
                                <img src="{{ $bannerUrl }}" alt="banner"
                                     style="max-width: 480px; width: 100%; border-radius: 12px; object-fit: cover; background: var(--bg-secondary);">
                            </div>
                        @else
                            <div class="mb-3"
                                 style="max-width: 480px; width: 100%; height: 160px; border-radius: 12px; background: var(--bg-secondary); display: flex; align-items: center; justify-content: center; color: var(--text-secondary);">
                                <i class="bi bi-image fs-1"></i>
                            </div>
                        @endif

                        <input type="file" name="image" accept="image/png,image/jpeg,image/webp"
                               class="form-control @error('image') is-invalid @enderror">
                        <small class="text-muted">JPG/PNG/WEBP, до 5 МБ. Загрузите новое изображение или замените текущее.</small>
                        @error('image')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror

                        @if($bannerUrl)
                            <div class="mt-2">
                                <label class="form-check">
                                    <input type="hidden" name="remove_image" value="0">
                                    <input type="checkbox" name="remove_image" value="1" class="form-check-input"
                                           style="background-color: var(--bg-secondary); border-color: var(--border);">
                                    <span class="form-check-label">Удалить текущий баннер</span>
                                </label>
                            </div>
                        @endif
                    </div>

                    {{-- Ссылка --}}
                    <div class="mb-4">
                        <label class="form-label">Ссылка баннера</label>
                        <input type="url" name="link"
                               class="form-control @error('link') is-invalid @enderror"
                               value="{{ old('link', $banner->link ?? '') }}"
                               placeholder="https://...">
                        @error('link')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                        <small class="text-muted">Куда ведёт баннер при нажатии (необязательно).</small>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-check-lg"></i> Сохранить
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
