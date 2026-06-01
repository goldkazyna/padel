@extends('layouts.app')
@section('title', 'Расписание')
@section('content')

@php
    $dayNames = ['', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
@endphp

<div class="coach-me-container">
    <div class="coach-me-header">
        <h1 class="coach-me-title">Расписание</h1>
        <p class="coach-me-sub">Моя карточка тренера</p>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="flash-message flash-error">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    @if(!$cc)
        <div class="empty-state">
            <p>Вы пока не привязаны к клубу как тренер. Обратитесь к администратору клуба.</p>
            <button type="button" class="btn-save" data-bs-toggle="modal" data-bs-target="#credModal">Изменить пароль</button>
        </div>
    @else
        {{-- Карточка тренера --}}
        <div class="coach-card">
            <div class="coach-card-main">
                <div class="coach-photo">
                    @if($cc->photo)
                        <img src="{{ $cc->photo }}" alt="{{ $cc->user->full_name }}">
                    @else
                        <span class="coach-photo-initials">{{ mb_strtoupper(mb_substr($cc->user->first_name ?? $cc->user->name ?? '?', 0, 1)) }}</span>
                    @endif
                </div>
                <div class="coach-info">
                    <div class="coach-name">{{ $cc->user->full_name }}</div>
                    <div class="coach-contacts">
                        @if($cc->user->phone)
                            <span class="coach-contact">@phoneFmt($cc->user->phone)</span>
                        @endif
                        @if($cc->user->email)
                            <span class="coach-contact">{{ $cc->user->email }}</span>
                        @endif
                        @if($cc->club)
                            <span class="coach-contact">{{ $cc->club->name }}</span>
                        @endif
                    </div>
                </div>
                <div class="coach-details">
                    @if($cc->specialization)
                        <div class="detail-group">
                            <span class="detail-label">Специализация</span>
                            <span class="detail-value">{{ $cc->specialization }}</span>
                        </div>
                    @endif
                    @if($cc->hourly_rate)
                        <div class="detail-group">
                            <span class="detail-label">Ставки</span>
                            <span class="detail-value rate-value">1ч: {{ number_format($cc->hourly_rate, 0, '', ' ') }} &#8376;</span>
                            @foreach($cc->rates->sortBy('hours') as $rate)
                                <span class="detail-value rate-value">{{ $rate->hours }}ч: {{ number_format($rate->rate, 0, '', ' ') }} &#8376;</span>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="coach-card-actions">
                    <button type="button" class="action-btn password" title="Изменить пароль" data-bs-toggle="modal" data-bs-target="#credModal">&#128273;</button>
                </div>
            </div>
        </div>

        {{-- Расписание (просмотр) --}}
        <div class="schedule-block">
            <h2 class="schedule-block-title">Моё расписание</h2>
            @if($cc->schedules && $cc->schedules->count())
                @php $byDay = $cc->schedules->sortBy('start_time')->groupBy('day_of_week'); @endphp
                <div class="schedule-days">
                    @for($d = 1; $d <= 7; $d++)
                        <div class="schedule-day-row {{ isset($byDay[$d]) ? '' : 'is-empty' }}">
                            <span class="schedule-day-name">{{ $dayNames[$d] }}</span>
                            <div class="schedule-day-slots">
                                @if(isset($byDay[$d]))
                                    @foreach($byDay[$d] as $s)
                                        <span class="schedule-tag">{{ \Carbon\Carbon::parse($s->start_time)->format('H:i') }}&ndash;{{ \Carbon\Carbon::parse($s->end_time)->format('H:i') }}</span>
                                    @endforeach
                                @else
                                    <span class="schedule-off">Выходной</span>
                                @endif
                            </div>
                        </div>
                    @endfor
                </div>
            @else
                <p class="schedule-empty-hint">Расписание ещё не настроено. Его задаёт администратор клуба.</p>
            @endif
        </div>
    @endif
</div>

<!-- Модалка: смена пароля -->
<div class="modal fade" id="credModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="modal-header" style="border-bottom: 1px solid #27272a; padding: 20px 24px;">
                <h5 class="modal-title" style="font-weight: 700;">Изменить пароль</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('coach.password') }}" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-body" style="padding: 24px;">
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
                </div>
                <div class="modal-footer" style="border-top: 1px solid #27272a; padding: 20px 24px;">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-save">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .coach-me-container { max-width: 1000px; margin: 0 auto; padding: 32px 24px; }
    .coach-me-header { margin-bottom: 24px; }
    .coach-me-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; margin: 0; }
    .coach-me-sub { color: #71717a; font-size: 14px; margin: 4px 0 0; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .coach-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; margin-bottom: 16px; overflow: hidden; }
    .coach-card-main { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; gap: 24px; flex-wrap: wrap; }
    .coach-photo { width: 64px; height: 64px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: linear-gradient(135deg, #22c55e, #16a34a); display: flex; align-items: center; justify-content: center; }
    .coach-photo img { width: 100%; height: 100%; object-fit: cover; }
    .coach-photo-initials { font-size: 26px; font-weight: 800; color: #0a0a0b; }
    .coach-info { display: flex; flex-direction: column; gap: 6px; min-width: 200px; flex: 1; }
    .coach-name { font-size: 18px; font-weight: 700; color: #f4f4f5; }
    .coach-contacts { display: flex; gap: 16px; flex-wrap: wrap; }
    .coach-contact { font-size: 13px; color: #71717a; font-weight: 500; }
    .coach-details { display: flex; gap: 32px; flex-wrap: wrap; flex: 1; }
    .detail-group { display: flex; flex-direction: column; gap: 4px; }
    .detail-label { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .detail-value { font-size: 14px; font-weight: 600; color: #a1a1aa; }
    .rate-value { color: #22c55e; }
    .coach-card-actions { display: flex; gap: 8px; }
    .action-btn { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 8px; cursor: pointer; color: #a1a1aa; font-size: 16px; transition: all 0.2s; text-decoration: none; }
    .action-btn.password:hover { border-color: #f59e0b; color: #f59e0b; }

    .schedule-block { background: #111113; border: 1px solid #27272a; border-radius: 16px; padding: 24px; }
    .schedule-block-title { font-size: 17px; font-weight: 800; margin: 0 0 18px; color: #f4f4f5; }
    .schedule-days { display: flex; flex-direction: column; gap: 4px; }
    .schedule-day-row { display: flex; align-items: center; gap: 16px; padding: 12px 14px; border-radius: 10px; }
    .schedule-day-row:not(.is-empty) { background: #16161a; }
    .schedule-day-name { font-size: 14px; font-weight: 700; color: #f4f4f5; min-width: 130px; }
    .schedule-day-slots { display: flex; gap: 8px; flex-wrap: wrap; }
    .schedule-tag { display: inline-flex; align-items: center; padding: 5px 12px; background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3); border-radius: 8px; font-size: 13px; font-weight: 700; color: #22c55e; }
    .schedule-off { font-size: 13px; color: #52525b; font-weight: 500; }
    .schedule-empty-hint { color: #71717a; font-size: 14px; margin: 0; }

    .empty-state { text-align: center; padding: 60px 20px; color: #71717a; }
    .empty-state p { font-size: 16px; margin-bottom: 20px; }

    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-hint { color: #52525b; font-size: 11px; display: block; margin-top: 6px; }
    .modal-footer { display: flex; gap: 12px; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }

    @media (max-width: 768px) {
        .coach-card-main { flex-direction: column; align-items: flex-start; }
        .schedule-day-name { min-width: 100px; }
    }
</style>
@endsection
