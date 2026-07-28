@extends('layouts.app')

@section('title', 'Настройки')

@section('content')

<div class="settings-container">
    <div class="settings-page-header">
        <h1 class="settings-page-title">Настройки</h1>
        <p class="settings-page-sub">Ваш аккаунт</p>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="flash-message flash-error">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    {{-- Профиль --}}
    <form action="{{ route('club.settings.profile') }}" method="POST" class="settings-card">
        @csrf
        @method('PUT')
        <h2 class="settings-card-title">Профиль</h2>

        <div class="form-group">
            <label class="form-label">Имя</label>
            <input type="text" name="name" class="form-input" value="{{ old('name', $user->name) }}" required>
        </div>

        <div class="form-group">
            <label class="form-label">Телефон</label>
            <input type="text" class="form-input form-input-readonly" value="@phoneFmt($user->phone)" readonly>
            <small class="form-hint">Телефон менять нельзя — это логин в систему</small>
        </div>

        <div class="settings-actions">
            <button type="submit" class="btn-save">Сохранить</button>
        </div>
    </form>

    {{-- Настройки клуба --}}
    @if(!empty($club))
    <form action="{{ route('club.settings.club') }}" method="POST" class="settings-card">
        @csrf
        @method('PUT')
        <h2 class="settings-card-title">Настройки клуба</h2>

        <label class="settings-toggle-row">
            <input type="hidden" name="allow_booking_without_payment" value="0">
            <input type="checkbox" name="allow_booking_without_payment" value="1"
                   {{ ($club->allow_booking_without_payment ?? true) ? 'checked' : '' }}>
            <span class="settings-toggle-text">
                <span class="settings-toggle-title">Показывать кнопку «Записаться без оплаты»</span>
                <small class="form-hint">Если включено — игроки могут записаться на турнир без онлайн-оплаты.</small>
            </span>
        </label>

        <label class="settings-toggle-row">
            <input type="hidden" name="auto_conduct_group_sessions" value="0">
            <input type="checkbox" name="auto_conduct_group_sessions" value="1"
                   {{ ($club->auto_conduct_group_sessions ?? false) ? 'checked' : '' }}>
            <span class="settings-toggle-text">
                <span class="settings-toggle-title">Автоматически проводить групповые занятия</span>
                <small class="form-hint">Если включено — после окончания занятия оно проводится само: всем участникам отмечается посещение и списываются часы (кроме заморозки; при пустом пакете — бесплатно).</small>
            </span>
        </label>

        <div class="form-group" style="margin-top:6px">
            <label class="form-label">Отмена брони — не позднее чем за (часов)</label>
            <input type="number" name="booking_cancel_hours" class="form-input" min="0" max="168"
                   value="{{ old('booking_cancel_hours', $club->booking_cancel_hours ?? 2) }}">
            <small class="form-hint">За сколько часов до начала клиент ещё может отменить бронь в приложении. 0 — отмена разрешена в любое время.</small>
        </div>

        {{-- Дизайн клубной карты в приложении --}}
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #27272a">
            <div style="font-size:14px;font-weight:800;color:#e4e4e7;margin-bottom:4px">Дизайн клубной карты</div>
            <div class="form-hint" style="margin-bottom:14px">Как выглядит клубная карта в приложении. Цвета берутся отсюда.</div>

            <div style="display:flex;gap:22px;flex-wrap:wrap;margin-bottom:18px">
                <label style="display:flex;flex-direction:column;gap:7px;font-size:11px;color:#a1a1aa;font-weight:700;text-transform:uppercase;letter-spacing:.4px">
                    Фон карты
                    <input type="color" id="cdBg" name="card_bg_color" value="{{ old('card_bg_color', $club->card_bg_color ?? '#1C2421') }}"
                           style="width:60px;height:40px;border:1px solid #2a3330;border-radius:9px;background:none;cursor:pointer;padding:2px">
                </label>
                <label style="display:flex;flex-direction:column;gap:7px;font-size:11px;color:#a1a1aa;font-weight:700;text-transform:uppercase;letter-spacing:.4px">
                    Акцент (слева / свечение)
                    <input type="color" id="cdAccent" name="card_accent_color" value="{{ old('card_accent_color', $club->card_accent_color ?? '#22C55E') }}"
                           style="width:60px;height:40px;border:1px solid #2a3330;border-radius:9px;background:none;cursor:pointer;padding:2px">
                </label>
                <label style="display:flex;flex-direction:column;gap:7px;font-size:11px;color:#a1a1aa;font-weight:700;text-transform:uppercase;letter-spacing:.4px">
                    Прогресс-бар
                    <input type="color" id="cdProg" name="card_progress_color" value="{{ old('card_progress_color', $club->card_progress_color ?? '#22C55E') }}"
                           style="width:60px;height:40px;border:1px solid #2a3330;border-radius:9px;background:none;cursor:pointer;padding:2px">
                </label>
            </div>

            {{-- Живое превью --}}
            <div id="cdCard" style="position:relative;width:300px;max-width:100%;height:184px;border-radius:18px;overflow:hidden;padding:18px;color:#fff;box-shadow:0 14px 34px rgba(0,0,0,.45)">
                <div id="cdStripe" style="position:absolute;left:0;top:0;bottom:0;width:5px"></div>
                <div style="position:relative;display:flex;justify-content:space-between;align-items:center">
                    <div style="width:38px;height:38px;border-radius:11px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:11px">ЛОГО</div>
                    <span style="font-size:9.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;padding:5px 9px;border-radius:20px;background:rgba(0,0,0,.28);border:1px solid rgba(255,255,255,.2)">Занятия</span>
                </div>
                <div style="position:relative;margin-top:14px;font-size:16px;font-weight:800">Абонемент 10 занятий</div>
                <div style="position:relative;margin-top:6px;font-size:26px;font-weight:900;letter-spacing:-.5px">8 <span style="font-size:13px;opacity:.7;font-weight:700">/ 10 ч</span></div>
                <div style="position:relative;height:6px;border-radius:4px;background:rgba(0,0,0,.32);margin-top:10px;overflow:hidden"><div id="cdBar" style="height:100%;width:80%"></div></div>
                <div style="position:absolute;left:18px;bottom:14px;font-family:monospace;font-size:12px;letter-spacing:1px;opacity:.85">EMC000064</div>
            </div>
        </div>

        {{-- Telegram-уведомления о бронях --}}
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #27272a">
            <div style="font-size:14px;font-weight:800;color:#e4e4e7;margin-bottom:4px">Telegram-уведомления о бронях</div>
            <div class="form-hint" style="margin-bottom:14px">Бот присылает уведомление, когда игрок бронирует корт или отменяет бронь в приложении. В сообщении — имя, телефон и ID игрока.</div>

            <label class="settings-toggle-row">
                <input type="hidden" name="telegram_notify_enabled" value="0">
                <input type="checkbox" name="telegram_notify_enabled" value="1"
                       {{ old('telegram_notify_enabled', $club->telegram_notify_enabled) ? 'checked' : '' }}>
                <span class="settings-toggle-text">
                    <span class="settings-toggle-title">Включить уведомления о бронях</span>
                    <small class="form-hint">Присылать в Telegram при новой брони и отмене (обычной и по клубной карте).</small>
                </span>
            </label>

            <div class="form-group" style="margin-top:6px">
                <label class="form-label">Токен бота</label>
                <input type="text" name="telegram_bot_token" class="form-input" autocomplete="off"
                       placeholder="{{ $club->telegram_bot_token ? '•••••••• (задан — оставьте пустым, чтобы не менять)' : '123456:ABC-DEF...' }}">
                <small class="form-hint">Создайте бота у @BotFather и вставьте его токен. Секрет не показывается — впишите заново, чтобы заменить.</small>
            </div>

            <div class="form-group" style="margin-top:6px">
                <label class="form-label">Кому слать (chat id)</label>
                <textarea name="telegram_chat_ids" class="form-input" rows="3"
                          placeholder="Один chat id в строке">{{ old('telegram_chat_ids', $club->telegram_chat_ids) }}</textarea>
                <small class="form-hint">Получатели — по одному в строке (или через запятую). Личный id узнают у @userinfobot; для группы/канала добавьте бота туда и укажите id (например -100…). Важно: получатель должен сначала написать боту /start.</small>
            </div>
        </div>

        <div class="settings-actions">
            <button type="submit" class="btn-save">Сохранить</button>
        </div>
    </form>

    {{-- Тест Telegram (отдельная форма — по уже сохранённым настройкам) --}}
    <form action="{{ route('club.settings.club.telegramTest') }}" method="POST" style="margin:-8px 0 8px">
        @csrf
        <button type="submit" class="btn-save" style="background:#1d211c;border:1px solid #2a3330;color:#e4e4e7">
            Отправить тестовое уведомление
        </button>
    </form>

    <script>
    (function(){
        var bg=document.getElementById('cdBg'),ac=document.getElementById('cdAccent'),pr=document.getElementById('cdProg');
        var card=document.getElementById('cdCard'),stripe=document.getElementById('cdStripe'),bar=document.getElementById('cdBar');
        if(!card) return;
        function shade(hex,p){
            var n=parseInt(hex.slice(1),16),r=(n>>16)&255,g=(n>>8)&255,b=n&255;
            r=Math.max(0,Math.min(255,Math.round(r+r*p)));
            g=Math.max(0,Math.min(255,Math.round(g+g*p)));
            b=Math.max(0,Math.min(255,Math.round(b+b*p)));
            return 'rgb('+r+','+g+','+b+')';
        }
        function upd(){
            card.style.background='linear-gradient(90deg,'+shade(bg.value,0.14)+' 0%,'+bg.value+' 50%,'+shade(bg.value,-0.26)+' 100%)';
            stripe.style.background='linear-gradient(180deg,'+ac.value+','+shade(ac.value,-0.4)+')';
            bar.style.background='linear-gradient(90deg,'+pr.value+','+shade(pr.value,0.35)+')';
        }
        [bg,ac,pr].forEach(function(i){i.addEventListener('input',upd);});
        upd();
    })();
    </script>
    @endif

    {{-- Смена пароля --}}
    <form action="{{ route('club.settings.password') }}" method="POST" class="settings-card">
        @csrf
        @method('PUT')
        <h2 class="settings-card-title">Смена пароля</h2>

        <div class="form-group">
            <label class="form-label">Текущий пароль</label>
            <input type="password" name="current_password" class="form-input" required autocomplete="current-password">
        </div>

        <div class="form-group">
            <label class="form-label">Новый пароль</label>
            <input type="password" name="password" class="form-input" required autocomplete="new-password">
            <small class="form-hint">Минимум 6 символов</small>
        </div>

        <div class="form-group">
            <label class="form-label">Повторите новый пароль</label>
            <input type="password" name="password_confirmation" class="form-input" required autocomplete="new-password">
        </div>

        <div class="settings-actions">
            <button type="submit" class="btn-save">Изменить пароль</button>
        </div>
    </form>
</div>

<style>
    .settings-container { max-width: 640px; margin: 0 auto; padding: 32px 24px; }
    .settings-page-header { margin-bottom: 24px; }
    .settings-page-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; margin: 0; }
    .settings-page-sub { color: #71717a; font-size: 14px; margin: 4px 0 0; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }

    .settings-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; padding: 24px; margin-bottom: 20px; }
    .settings-card-title { font-size: 17px; font-weight: 800; margin: 0 0 20px; color: #f4f4f5; }

    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input-readonly { background: #0e0e10; color: #71717a; cursor: not-allowed; }
    .form-input-readonly:focus { border-color: #27272a; box-shadow: none; }
    .form-hint { color: #52525b; font-size: 11px; display: block; margin-top: 6px; }

    .settings-toggle-row { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; margin-bottom: 20px; }
    .settings-toggle-row input[type="checkbox"] { width: 20px; height: 20px; margin-top: 2px; accent-color: #22c55e; cursor: pointer; flex-shrink: 0; }
    .settings-toggle-text { display: block; }
    .settings-toggle-title { display: block; font-size: 15px; font-weight: 600; color: #f4f4f5; }

    .settings-actions { margin-top: 4px; }
    .btn-save { padding: 13px 28px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }
</style>
@endsection
