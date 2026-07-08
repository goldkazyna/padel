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

                    {{-- Обложка --}}
                    @php
                        $currentCover = $club->cover;
                        if (!$currentCover) {
                            $coverUrl = null;
                        } elseif (preg_match('#^https?://#', $currentCover)) {
                            $coverUrl = $currentCover;
                        } elseif (str_starts_with($currentCover, '/')) {
                            // Формат /covers/x.jpg
                            $coverUrl = url($currentCover);
                        } else {
                            // Старые записи без префикса
                            $coverUrl = asset('covers/' . $currentCover);
                        }
                    @endphp
                    <div class="mb-4">
                        <label class="form-label">Обложка клуба</label>
                        <div class="d-flex align-items-center gap-3 mb-2">
                            @if($coverUrl)
                                <img src="{{ $coverUrl }}" alt="cover"
                                     style="width: 120px; height: 72px; border-radius: 12px; object-fit: cover; background: var(--bg-secondary);">
                            @else
                                <div style="width: 120px; height: 72px; border-radius: 12px; background: var(--bg-secondary); display: flex; align-items: center; justify-content: center; color: var(--text-secondary);">
                                    <i class="bi bi-image fs-3"></i>
                                </div>
                            @endif
                            <div class="flex-grow-1">
                                <input type="file" name="cover" accept="image/*"
                                       class="form-control @error('cover') is-invalid @enderror">
                                <small class="text-muted">Любой формат изображения. Замените существующую или загрузите новую.</small>
                                @error('cover')
                                    <div class="text-danger mt-2 small">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        @if($coverUrl)
                            <label class="form-check">
                                <input type="hidden" name="remove_cover" value="0">
                                <input type="checkbox" name="remove_cover" value="1" class="form-check-input"
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Удалить текущую обложку</span>
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
                            @foreach(['Алматы', 'Астана', 'Шымкент', 'Караганда', 'Актобе', 'Костанай'] as $city)
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

                    {{-- Онлайн-оплата и документы --}}
                    <div class="mb-4">
                        <label class="form-check">
                            <input type="hidden" name="online_payment_enabled" value="0">
                            <input type="checkbox" name="online_payment_enabled" value="1" class="form-check-input"
                                   {{ old('online_payment_enabled', $club->online_payment_enabled) ? 'checked' : '' }}
                                   style="background-color: var(--bg-secondary); border-color: var(--border);">
                            <span class="form-check-label">Оплата онлайн</span>
                        </label>
                        <label class="form-check mt-2">
                            <input type="hidden" name="allow_booking_without_payment" value="0">
                            <input type="checkbox" name="allow_booking_without_payment" value="1" class="form-check-input"
                                   {{ old('allow_booking_without_payment', $club->allow_booking_without_payment ?? true) ? 'checked' : '' }}
                                   style="background-color: var(--bg-secondary); border-color: var(--border);">
                            <span class="form-check-label">Показывать кнопку «Записаться без оплаты»</span>
                        </label>
                    </div>

                    {{-- Ключи платёжного шлюза Plexy (у каждого клуба свои) --}}
                    <div class="mb-4 p-3" style="border:1px solid var(--border); border-radius:10px;">
                        <div class="mb-3 fw-bold"><i class="bi bi-credit-card"></i> Plexy (онлайн-оплата)</div>

                        <div class="mb-3">
                            <label class="form-label">Merchant ID</label>
                            <input type="text" name="plexy_merchant_id" class="form-control @error('plexy_merchant_id') is-invalid @enderror"
                                   value="{{ old('plexy_merchant_id', $club->plexy_merchant_id) }}"
                                   placeholder="идентификатор мерчанта">
                            @error('plexy_merchant_id')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">API ключ</label>
                            <input type="text" name="plexy_api_key" class="form-control @error('plexy_api_key') is-invalid @enderror"
                                   value="" autocomplete="off"
                                   placeholder="{{ $club->plexy_api_key ? '•••••••• (задан — оставьте пустым, чтобы не менять)' : 'не задан' }}">
                            @error('plexy_api_key')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                            <small class="text-muted">Секрет не показывается. Введите новое значение, чтобы заменить.</small>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Секрет вебхука</label>
                            <input type="text" name="plexy_webhook_secret" class="form-control @error('plexy_webhook_secret') is-invalid @enderror"
                                   value="" autocomplete="off"
                                   placeholder="{{ $club->plexy_webhook_secret ? '•••••••• (задан — оставьте пустым, чтобы не менять)' : 'не задан' }}">
                            @error('plexy_webhook_secret')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                            <small class="text-muted">Если не заполнено у клуба — используется значение из env по умолчанию.</small>
                        </div>
                    </div>

                    @php
                        $clubDocs = [
                            'offer_agreement' => 'Договор оферты',
                            'privacy_policy' => 'Политика конфиденциальности',
                            'goods_description' => 'Описание товара или услуг',
                            'card_payment_description' => 'Описание оплаты банковской картой',
                        ];
                    @endphp
                    @foreach($clubDocs as $docField => $docLabel)
                        @php
                            $docPath = $club->$docField;
                            $docUrl = $docPath
                                ? (preg_match('#^https?://#', $docPath) ? $docPath : url($docPath))
                                : null;
                        @endphp
                        <div class="mb-4">
                            <label class="form-label">{{ $docLabel }}</label>
                            @if($docUrl)
                                <div class="mb-2">
                                    <a href="{{ $docUrl }}" target="_blank" rel="noopener" class="small">
                                        <i class="bi bi-file-earmark-text"></i> Текущий файл
                                    </a>
                                </div>
                            @endif
                            <input type="file" name="{{ $docField }}" accept=".pdf,.doc,.docx,image/*"
                                   class="form-control @error($docField) is-invalid @enderror">
                            <small class="text-muted">PDF/DOC/DOCX или изображение, до 10 МБ.</small>
                            @error($docField)
                                <div class="text-danger mt-2 small">{{ $message }}</div>
                            @enderror
                            @if($docUrl)
                                <label class="form-check mt-2">
                                    <input type="hidden" name="remove_{{ $docField }}" value="0">
                                    <input type="checkbox" name="remove_{{ $docField }}" value="1" class="form-check-input"
                                           style="background-color: var(--bg-secondary); border-color: var(--border);">
                                    <span class="form-check-label">Удалить текущий файл</span>
                                </label>
                            @endif
                        </div>
                    @endforeach

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
                        <label class="form-check">
                            <input type="hidden" name="coming_soon" value="0">
                            <input type="checkbox" name="coming_soon" value="1" class="form-check-input"
                                   {{ old('coming_soon', $club->coming_soon) ? 'checked' : '' }}
                                   style="background-color: var(--bg-secondary); border-color: var(--border);">
                            <span class="form-check-label">Скоро открытие</span>
                        </label>
                        <div class="text-secondary small mt-1">В приложении у клуба будет плашка «Скоро открытие».</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-check">
                            <input type="hidden" name="is_community" value="0">
                            <input type="checkbox" name="is_community" value="1" class="form-check-input"
                                   {{ old('is_community', $club->is_community) ? 'checked' : '' }}
                                   style="background-color: var(--bg-secondary); border-color: var(--border);">
                            <span class="form-check-label">Сообщество (community)</span>
                        </label>
                        <div class="text-secondary small mt-1">Включено — это сообщество, выключено — клуб.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Телеграм-канал (ссылка)</label>
                        <input type="text" name="telegram_url" class="form-control @error('telegram_url') is-invalid @enderror"
                               value="{{ old('telegram_url', $club->telegram_url) }}" placeholder="https://t.me/yourchannel">
                        @error('telegram_url')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                        <small class="text-muted">Публичная ссылка на телеграм-канал клуба (видна игрокам).</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Инстаграм (ссылка)</label>
                        <input type="text" name="instagram_url" class="form-control @error('instagram_url') is-invalid @enderror"
                               value="{{ old('instagram_url', $club->instagram_url) }}" placeholder="https://instagram.com/yourclub">
                        @error('instagram_url')
                            <div class="text-danger mt-2 small">{{ $message }}</div>
                        @enderror
                        <small class="text-muted">Публичная ссылка на инстаграм клуба (видна игрокам).</small>
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
                            <label class="form-check">
                                <input type="hidden" name="features[groups]" value="0">
                                <input type="checkbox" name="features[groups]" value="1" class="form-check-input"
                                       {{ old('features.groups', $features['groups'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Групповые занятия</span>
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