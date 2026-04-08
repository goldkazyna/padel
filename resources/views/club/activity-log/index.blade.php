@extends('layouts.app')
@section('title', 'Журнал действий')
@section('content')

<div class="log-container">
    <div class="log-header">
        <h1 class="log-title">Журнал — {{ $club->name ?? '' }}</h1>
    </div>

    <!-- Фильтры -->
    <form method="GET" class="log-filters">
        <select name="action" class="log-filter-select" onchange="this.form.submit()">
            <option value="">Все действия</option>
            <option value="created" {{ request('action') === 'created' ? 'selected' : '' }}>Создание</option>
            <option value="updated" {{ request('action') === 'updated' ? 'selected' : '' }}>Редактирование</option>
            <option value="cancelled" {{ request('action') === 'cancelled' ? 'selected' : '' }}>Отмена</option>
            <option value="blocked" {{ request('action') === 'blocked' ? 'selected' : '' }}>Блокировка</option>
            <option value="unblocked" {{ request('action') === 'unblocked' ? 'selected' : '' }}>Разблокировка</option>
        </select>
        <select name="user_id" class="log-filter-select" onchange="this.form.submit()">
            <option value="">Все пользователи</option>
            @foreach($users as $u)
                <option value="{{ $u->id }}" {{ request('user_id') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
            @endforeach
        </select>
    </form>

    <!-- Логи -->
    @forelse($logs as $log)
        <div class="log-card">
            <div class="log-card-left">
                <div class="log-action-badge {{ $log->action }}">
                    @switch($log->action)
                        @case('created') <i class="bi bi-plus-circle"></i> @break
                        @case('updated') <i class="bi bi-pencil"></i> @break
                        @case('cancelled') <i class="bi bi-x-circle"></i> @break
                        @case('blocked') <i class="bi bi-lock"></i> @break
                        @case('unblocked') <i class="bi bi-unlock"></i> @break
                        @default <i class="bi bi-activity"></i>
                    @endswitch
                </div>
                <div class="log-info">
                    <div class="log-description">{{ $log->description }}</div>
                    <div class="log-meta">
                        <span class="log-user">{{ $log->user->name ?? 'Система' }}</span>
                        <span class="log-dot">·</span>
                        <span class="log-time">{{ $log->created_at->format('d.m.Y H:i') }}</span>
                        <span class="log-dot">·</span>
                        <span class="log-time">{{ $log->created_at->diffForHumans() }}</span>
                    </div>
                    @if($log->changes)
                        <div class="log-changes">
                            @foreach($log->changes as $field => $value)
                                @if(!in_array($field, ['updated_at']))
                                    <span class="log-change-tag">{{ $field }}: {{ is_array($value) ? json_encode($value) : $value }}</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="empty-state">
            <i class="bi bi-clock-history" style="font-size: 48px; color: #3f3f46;"></i>
            <p>Нет записей</p>
        </div>
    @endforelse

    <!-- Пагинация -->
    @if($logs->hasPages())
        <div class="log-pagination">
            {{ $logs->appends(request()->query())->links('pagination::simple-default') }}
        </div>
    @endif
</div>

<style>
    .log-container { max-width: 900px; margin: 0 auto; padding: 32px 24px; }
    .log-header { margin-bottom: 20px; }
    .log-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }

    .log-filters { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
    .log-filter-select { background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 10px 14px; color: #a1a1aa; font-size: 13px; font-weight: 600; cursor: pointer; min-width: 160px; }
    .log-filter-select:focus { outline: none; border-color: #22c55e; }

    .log-card { display: flex; padding: 14px 16px; background: #111113; border: 1px solid #27272a; border-radius: 12px; margin-bottom: 6px; }
    .log-card-left { display: flex; gap: 12px; flex: 1; }

    .log-action-badge { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
    .log-action-badge.created { background: rgba(34,197,94,0.12); color: #22c55e; }
    .log-action-badge.updated { background: rgba(59,130,246,0.12); color: #3b82f6; }
    .log-action-badge.cancelled { background: rgba(239,68,68,0.12); color: #ef4444; }
    .log-action-badge.blocked { background: rgba(251,146,60,0.12); color: #fb923c; }
    .log-action-badge.unblocked { background: rgba(167,139,250,0.12); color: #a78bfa; }

    .log-description { font-size: 14px; font-weight: 600; margin-bottom: 3px; }
    .log-meta { display: flex; gap: 6px; align-items: center; font-size: 12px; color: #71717a; }
    .log-user { color: #a1a1aa; font-weight: 600; }
    .log-dot { color: #3f3f46; }
    .log-changes { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
    .log-change-tag { padding: 2px 6px; background: #1a1a1a; border: 1px solid #27272a; border-radius: 4px; font-size: 10px; color: #71717a; }

    .empty-state { text-align: center; padding: 60px 20px; color: #71717a; }
    .empty-state p { font-size: 16px; margin-top: 12px; }

    .log-pagination { margin-top: 20px; display: flex; justify-content: center; }
    .log-pagination nav { display: flex; gap: 8px; }
</style>
@endsection
