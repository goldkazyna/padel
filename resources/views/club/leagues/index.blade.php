@extends('layouts.app')

@section('title', 'Лиги')

@section('content')
<div class="leagues-container">
    <div class="leagues-header">
        <div>
            <div class="leagues-title">
                Лиги
                @if($club)<span class="leagues-title-club">— {{ $club->name }}</span>@endif
            </div>
            <div class="leagues-sub">Серия турниров с общим составом и одной таблицей</div>
        </div>
        <a href="{{ route('club.leagues.create') }}" class="btn-add">
            <i class="bi bi-plus-lg"></i> Создать лигу
        </a>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    @if($leagues->isEmpty())
        <div class="empty-state">
            <i class="bi bi-collection"></i>
            <div class="empty-title">Лиг пока нет</div>
            <div class="empty-text">
                Лига — это несколько турниров подряд с общим составом и одной таблицей.
                Например, «Сентябрь Кап» из восьми этапов Americano Flex.
            </div>
            <a href="{{ route('club.leagues.create') }}" class="btn-add">
                <i class="bi bi-plus-lg"></i> Создать первую лигу
            </a>
        </div>
    @else
        <div class="leagues-grid">
            @foreach($leagues as $league)
                @php
                    $done = $league->finishedStagesCount();
                    $total = max($league->stages_planned, $league->stages_count);
                    $percent = $total > 0 ? round($done / $total * 100) : 0;
                @endphp
                <a href="{{ route('club.leagues.show', $league) }}" class="lg-card">
                    <div class="lg-top">
                        <div>
                            <div class="lg-name">{{ $league->name }}</div>
                            <div class="lg-note">
                                @if($league->start_date)
                                    {{ $league->start_date->locale('ru')->translatedFormat('j M') }}
                                    @if($league->end_date)
                                        — {{ $league->end_date->locale('ru')->translatedFormat('j M Y') }}
                                    @endif
                                    ·
                                @endif
                                {{ $league->players_count }}
                                {{ trans_choice('участник|участника|участников', $league->players_count) }}
                            </div>
                        </div>
                        <span class="lg-status lg-{{ $league->status }}">
                            @if($league->status === 'in_progress')<span class="lg-live"></span>@endif
                            {{ $league->status_name }}
                        </span>
                    </div>

                    <div class="lg-progress">
                        <div class="lg-bar"><span style="width: {{ $percent }}%"></span></div>
                        <div class="lg-progress-text">Этапов сыграно: {{ $done }} из {{ $total }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>

<style>
.leagues-container { max-width: 1200px; margin: 0 auto; padding: 24px 16px 40px; }
.leagues-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 16px; }
.leagues-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
.leagues-title-club { color: #71717a; font-weight: 500; }
.leagues-sub { color: #71717a; font-size: 13px; margin-top: 4px; }
.btn-add { display: flex; align-items: center; gap: 8px; background: #22c55e; color: #0a0a0b; border: none; padding: 12px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: background 0.2s; text-decoration: none; }
.btn-add:hover { background: #16a34a; color: #0a0a0b; }

.flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
.flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
.flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

.leagues-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start; }
@media (max-width: 900px) { .leagues-grid { grid-template-columns: 1fr; } }

.lg-card { display: block; background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 16px 18px; text-decoration: none; transition: border-color 0.15s, background 0.15s; }
.lg-card:hover { border-color: rgba(255,255,255,0.14); background: #171a1e; }
.lg-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.lg-name { font-size: 17px; font-weight: 800; color: #f4f6f7; line-height: 1.25; overflow-wrap: anywhere; }
.lg-note { font-size: 13px; color: #7c848a; margin-top: 5px; }
.lg-status { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .3px; background: rgba(139,146,152,0.14); color: #8b9298; }
.lg-open { background: rgba(34,197,94,0.14); color: #34d17f; }
.lg-in_progress { background: rgba(251,191,36,0.14); color: #fbbf24; }
.lg-cancelled { background: rgba(239,68,68,0.14); color: #ef4444; }
.lg-live { width: 7px; height: 7px; border-radius: 50%; background: #fbbf24; box-shadow: 0 0 0 0 rgba(251,191,36,0.45); animation: lgpulse 2s infinite; }
@keyframes lgpulse { 0% { box-shadow: 0 0 0 0 rgba(251,191,36,0.45); } 70% { box-shadow: 0 0 0 6px rgba(251,191,36,0); } 100% { box-shadow: 0 0 0 0 rgba(251,191,36,0); } }
@media (prefers-reduced-motion: reduce) { .lg-live { animation: none; } }

.lg-progress { margin-top: 16px; }
.lg-bar { height: 6px; border-radius: 6px; background: rgba(255,255,255,0.07); overflow: hidden; }
.lg-bar span { display: block; height: 100%; background: #22c55e; }
.lg-progress-text { margin-top: 7px; font-size: 12px; color: #7c848a; }

.empty-state { background: #15181A; border: 1px dashed rgba(255,255,255,0.10); border-radius: 16px; padding: 56px 24px; text-align: center; }
.empty-state i { font-size: 30px; color: #3f3f46; display: block; margin-bottom: 14px; }
.empty-title { font-size: 16px; font-weight: 700; color: #f4f6f7; }
.empty-text { font-size: 13.5px; color: #7c848a; margin: 8px auto 20px; max-width: 460px; line-height: 1.5; }
.empty-state .btn-add { display: inline-flex; }
</style>
@endsection
