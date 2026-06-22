@extends('layouts.app')
@section('title', 'Редактирование тренера')
@section('content')

<div class="coach-edit-container">
    <div class="coach-edit-header">
        <a href="{{ route('club.coaches.index') }}" class="back-btn"><i class="bi bi-arrow-left"></i></a>
        <h1 class="coach-edit-title">Редактирование — {{ $cc->user->full_name }}</h1>
    </div>

    @if($errors->any())
        <div class="flash-message flash-error">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    <form action="{{ route('club.coaches.update', $cc->user_id) }}" method="POST" enctype="multipart/form-data" class="coach-edit-card">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label class="form-label">Фотография тренера</label>
            <div class="photo-row">
                <div class="photo-preview">
                    @if($cc->photo)
                        <img src="{{ $cc->photo }}" alt="фото">
                    @else
                        <i class="bi bi-person"></i>
                    @endif
                </div>
                <div style="flex:1;">
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-input" style="padding:8px;">
                    <small class="form-hint">JPG/PNG/WebP. Размер изображения от 500×500 до 2000×2000 пикселей, до 4 МБ.</small>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Специализация</label>
            <input type="text" name="specialization" class="form-input" value="{{ $cc->specialization }}" placeholder="Например: Начинающие, Продвинутые">
        </div>

        <div class="form-group">
            <label class="form-label">Информация о тренере</label>
            <textarea name="info" class="form-input" rows="4" placeholder="Опыт, достижения, подход к тренировкам...">{{ $cc->info }}</textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Рейтинг</label>
            <input type="text" name="rating" class="form-input" value="{{ $cc->rating }}" placeholder="Например: 5.0 или «Профессионал»">
        </div>

        <div class="form-group">
            <label class="form-label">Сертификаты</label>
            @if(!empty($cc->certificates))
                <div class="certificates-grid">
                    @foreach($cc->certificates as $cert)
                        <label class="certificate-item">
                            <img src="{{ $cert }}" alt="сертификат">
                            <span class="certificate-remove">
                                <input type="checkbox" name="remove_certificates[]" value="{{ $cert }}">
                                <i class="bi bi-trash3"></i> Удалить
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
            <input type="file" name="certificates[]" accept="image/jpeg,image/png,image/webp" class="form-input" style="padding:8px;" multiple>
            <small class="form-hint">Можно выбрать несколько файлов. JPG/PNG/WebP, до 8 МБ каждый. Отметьте «Удалить» под существующим, чтобы убрать его.</small>
        </div>

        <div class="form-group">
            <label class="form-label">Ставка за 1 час (базовая)</label>
            <input type="number" name="hourly_rate" class="form-input" value="{{ $cc->hourly_rate ? intval($cc->hourly_rate) : '' }}" placeholder="&#8376;/час" min="0" step="100">
        </div>

        <div class="form-group">
            <label class="form-label">Ставка за групповое занятие (&#8376;/час)</label>
            <input type="number" name="rate_group" class="form-input" value="{{ $cc->rate_group ? intval($cc->rate_group) : '' }}" placeholder="&#8376;/час" min="0" step="100">
        </div>

        <div class="form-group">
            <label class="form-label">Ставка за индивидуальное занятие (&#8376;/час)</label>
            <input type="number" name="rate_individual" class="form-input" value="{{ $cc->rate_individual ? intval($cc->rate_individual) : '' }}" placeholder="&#8376;/час" min="0" step="100">
        </div>

        <div class="form-group">
            <label class="form-label">Ставки по длительности</label>
            <div class="rates-grid">
                @for($h = 2; $h <= 6; $h++)
                    @php $existingRate = $cc->rates->firstWhere('hours', $h); @endphp
                    <div class="rate-row">
                        <span class="rate-label">{{ $h }} {{ $h <= 4 ? 'часа' : 'часов' }}</span>
                        <input type="hidden" name="rates[{{ $h - 2 }}][hours]" value="{{ $h }}">
                        <input type="number" name="rates[{{ $h - 2 }}][rate]" class="form-input rate-input" value="{{ $existingRate ? intval($existingRate->rate) : '' }}" placeholder="&#8376;" min="0" step="100">
                    </div>
                @endfor
            </div>
            <small class="form-hint">Если не указано — считается по базовой ставке × часы</small>
        </div>

        <div class="coach-edit-actions">
            <a href="{{ route('club.coaches.index') }}" class="btn-cancel">Отмена</a>
            <button type="submit" class="btn-save">Сохранить</button>
        </div>
    </form>
</div>

<style>
    .coach-edit-container { max-width: 640px; margin: 0 auto; padding: 32px 24px; }
    .coach-edit-header { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
    .back-btn { width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; text-decoration: none; font-size: 18px; }
    .back-btn:hover { border-color: #3f3f46; color: #f4f4f5; }
    .coach-edit-title { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; margin: 0; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .coach-edit-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; padding: 24px; }
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-hint { color: #52525b; font-size: 11px; display: block; margin-top: 6px; }

    .photo-row { display: flex; align-items: center; gap: 16px; }
    .photo-preview { width: 80px; height: 80px; border-radius: 16px; overflow: hidden; background: #16161a; border: 1px solid #27272a; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .photo-preview img { width: 100%; height: 100%; object-fit: cover; }
    .photo-preview i { font-size: 34px; color: #52525b; }

    .certificates-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; margin-bottom: 12px; }
    .certificate-item { display: flex; flex-direction: column; gap: 6px; cursor: pointer; }
    .certificate-item img { width: 100%; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid #27272a; }
    .certificate-remove { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #a1a1aa; }
    .certificate-remove input { width: 16px; height: 16px; margin: 0; cursor: pointer; }
    .rates-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
    .rate-row { display: flex; align-items: center; gap: 8px; }
    .rate-label { font-size: 13px; color: #a1a1aa; white-space: nowrap; min-width: 60px; }
    .rate-input { padding: 10px 12px; }

    .coach-edit-actions { display: flex; gap: 12px; margin-top: 28px; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; }
    .btn-cancel:hover { border-color: #3f3f46; color: #f4f4f5; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }
</style>
@endsection
