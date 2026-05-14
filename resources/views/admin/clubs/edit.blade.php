@extends('layouts.app')

@section('title', 'Редактировать клуб')

@section('content')
<div class="page-header">
    <div>
        <h2>Редактировать клуб</h2>
        <p>{{ $club->name }}</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card-dark">
            <div class="card-body">
                <form action="{{ route('admin.clubs.update', $club) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    {{-- Логотип --}}
                    @php
                        $currentLogo = $club->logo;
                        if (!$currentLogo) {
                            $logoUrl = null;
                        } elseif (preg_match('#^https?://#', $currentLogo)) {
                            $logoUrl = $currentLogo;
                        } elseif (str_starts_with($currentLogo, '/')) {
                            // Формат /logos/x.jpg — как у существующих клубов
                            $logoUrl = url($currentLogo);
                        } else {
                            // Старые записи без префикса
                            $logoUrl = asset('logos/' . $currentLogo);
                        }
                    @endphp
                    <div class="mb-4">
                        <label class="form-label">Логотип клуба</label>
                        <div class="d-flex align-items-center gap-3 mb-2">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="logo"
                                     style="width: 72px; height: 72px; border-radius: 12px; object-fit: cover; background: var(--bg-secondary);">
                            @else
                                <div style="width: 72px; height: 72px; border-radius: 12px; background: var(--bg-secondary); display: flex; align-items: center; justify-content: center; color: var(--text-secondary);">
                                    <i class="bi bi-image fs-3"></i>
                                </div>
                            @endif
                            <div class="flex-grow-1">
                                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"
                                       class="form-control @error('logo') is-invalid @enderror">
                                <small class="text-muted">JPG/PNG/WEBP, до 2 МБ. Замените существующий или загрузите новый.</small>
                                @error('logo')
                                    <div class="text-danger mt-2 small">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        @if($logoUrl)
                            <label class="form-check">
                                <input type="hidden" name="remove_logo" value="0">
                                <input type="checkbox" name="remove_logo" value="1" class="form-check-input"
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Удалить текущий логотип</span>
                            </label>
                        @endif
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Название *</label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" 
                               value="{{ old('name', $club->name) }}" required>
                        @error('name')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Адрес *</label>
                        <input type="text" name="address" class="form-control @error('address') is-invalid @enderror" 
                               value="{{ old('address', $club->address) }}" required>
                        @error('address')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Город</label>
                        <select name="city" class="form-control @error('city') is-invalid @enderror"
                                style="background-color: var(--bg-secondary); border-color: var(--border); color: var(--text);">
                            <option value="">— Не указан —</option>
                            @foreach(['Алматы', 'Астана', 'Шымкент', 'Караганда', 'Актобе'] as $city)
                                <option value="{{ $city }}" {{ old('city', $club->city) === $city ? 'selected' : '' }}>{{ $city }}</option>
                            @endforeach
                        </select>
                        @error('city')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Телефон</label>
                        <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror" 
                               value="{{ old('phone', $club->phone) }}">
                        @error('phone')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" 
                               value="{{ old('email', $club->email) }}">
                        @error('email')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Описание</label>
                        <textarea name="description" class="form-control @error('description') is-invalid @enderror" 
                                  rows="4">{{ old('description', $club->description) }}</textarea>
                        @error('description')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Ссылка на оплату</label>
                        <input type="url" name="payment_url" class="form-control @error('payment_url') is-invalid @enderror"
                               value="{{ old('payment_url', $club->payment_url) }}" placeholder="https://kaspi.kz/pay/...">
                        @error('payment_url')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                        <small class="text-muted">Ссылка на страницу оплаты (Kaspi, PayBox и др.)</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-check">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input"
                                   {{ old('is_active', $club->is_active) ? 'checked' : '' }}
                                   style="background-color: var(--bg-secondary); border-color: var(--border);">
                            <span class="form-check-label">Клуб активен</span>
                        </label>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Telegram — ID канала</label>
                        <input type="text" name="telegram_channel_id" class="form-control @error('telegram_channel_id') is-invalid @enderror"
                               value="{{ old('telegram_channel_id', $club->telegram_channel_id) }}" placeholder="@channel или -100123456789">
                        @error('telegram_channel_id')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                        <small class="text-muted">ID канала (@username или числовой ID). Если пусто — используется общий канал.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Telegram — Bot Token</label>
                        <input type="text" name="telegram_bot_token" class="form-control @error('telegram_bot_token') is-invalid @enderror"
                               value="{{ old('telegram_bot_token', $club->telegram_bot_token) }}" placeholder="123456:ABC-DEF...">
                        @error('telegram_bot_token')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                        <small class="text-muted">Токен бота для этого клуба. Если пусто — используется общий бот.</small>
                    </div>

                    @php($features = $club->features ?? [])
                    <div class="mb-4">
                        <label class="form-label">Доступные модули</label>
                        <div class="d-flex flex-column gap-2">
                            <label class="form-check">
                                <input type="hidden" name="features[tournaments]" value="0">
                                <input type="checkbox" name="features[tournaments]" value="1" class="form-check-input"
                                       {{ old('features.tournaments', $features['tournaments'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Турниры</span>
                            </label>
                            <label class="form-check">
                                <input type="hidden" name="features[users]" value="0">
                                <input type="checkbox" name="features[users]" value="1" class="form-check-input"
                                       {{ old('features.users', $features['users'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Пользователи</span>
                            </label>
                            <label class="form-check">
                                <input type="hidden" name="features[courts]" value="0">
                                <input type="checkbox" name="features[courts]" value="1" class="form-check-input"
                                       {{ old('features.courts', $features['courts'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Корты</span>
                            </label>
                            <label class="form-check">
                                <input type="hidden" name="features[coaches]" value="0">
                                <input type="checkbox" name="features[coaches]" value="1" class="form-check-input"
                                       {{ old('features.coaches', $features['coaches'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Тренеры</span>
                            </label>
                            <label class="form-check">
                                <input type="hidden" name="features[coach_booking]" value="0">
                                <input type="checkbox" name="features[coach_booking]" value="1" class="form-check-input"
                                       {{ old('features.coach_booking', $features['coach_booking'] ?? false) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Бронирование тренеров (в приложении)</span>
                            </label>
                            <label class="form-check">
                                <input type="hidden" name="features[clients]" value="0">
                                <input type="checkbox" name="features[clients]" value="1" class="form-check-input"
                                       {{ old('features.clients', $features['clients'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Клиенты</span>
                            </label>
                            <label class="form-check">
                                <input type="hidden" name="features[activity_log]" value="0">
                                <input type="checkbox" name="features[activity_log]" value="1" class="form-check-input"
                                       {{ old('features.activity_log', $features['activity_log'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Журнал</span>
                            </label>
                            <label class="form-check">
                                <input type="hidden" name="features[moderators]" value="0">
                                <input type="checkbox" name="features[moderators]" value="1" class="form-check-input"
                                       {{ old('features.moderators', $features['moderators'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Менеджеры / Модераторы</span>
                            </label>
                        </div>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-check-lg"></i> Сохранить
                        </button>
                        <a href="{{ route('admin.clubs.index') }}" class="btn-outline-custom">Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection