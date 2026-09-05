@extends('layouts.app')

@php
    $reasonNames = [
        'spam' => 'Спам и реклама',
        'abuse' => 'Оскорбления',
        'fraud' => 'Мошенничество',
        'other' => 'Другое',
    ];
@endphp

@section('content')
<style>
    .rep-one { max-width: 900px; }
    .rep-back { color: var(--text-secondary); text-decoration: none; font-size: 13px; }
    .rep-back:hover { color: var(--text-primary); }

    .rep-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 20px;
        margin-top: 16px;
    }
    .rep-card h1 { font-size: 20px; font-weight: 700; margin: 0 0 4px; color: var(--text-primary); }
    .rep-muted { color: var(--text-muted); font-size: 13px; }
    .rep-label { color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
    .rep-value { color: var(--text-primary); font-size: 15px; }

    .rep-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 18px; }
    @media (max-width: 640px) { .rep-grid { grid-template-columns: 1fr; } }

    .rep-done {
        padding: 9px 16px; border-radius: 10px; border: 0;
        background: var(--accent); color: #0c0e0f; font-size: 13px; font-weight: 700; cursor: pointer;
    }
    .rep-status {
        padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 700;
        background: rgba(156, 163, 175, .15); color: var(--text-secondary);
    }
    .rep-flag { display: inline-block; margin-top: 6px; padding: 4px 9px; border-radius: 6px;
        background: rgba(239, 68, 68, .14); color: #ef4444; font-size: 11px; font-weight: 700; }

    .rep-msg { padding: 12px 0; border-bottom: 1px solid var(--border); }
    .rep-msg:last-child { border-bottom: 0; }
    .rep-msg-head { font-size: 12px; color: var(--text-muted); margin-bottom: 3px; }
    .rep-msg-accused { color: #f59e0b; font-weight: 700; }
    .rep-msg-text { color: var(--text-primary); font-size: 14px; line-height: 1.5; }
</style>

<div class="rep-one">
    <a href="{{ route('admin.reports.index') }}" class="rep-back">← К списку жалоб</a>

    <div class="rep-card">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px;">
            <div>
                <h1>{{ $reasonNames[$report->reason] ?? $report->reason }}</h1>
                <div class="rep-muted">{{ $report->created_at?->translatedFormat('j F Y, H:i') }}</div>
            </div>
            @if($report->status === \App\Models\ContentReport::STATUS_NEW)
                <form method="POST" action="{{ route('admin.reports.review', $report) }}">
                    @csrf
                    <button class="rep-done">Разобрано</button>
                </form>
            @else
                <span class="rep-status">Разобрана</span>
            @endif
        </div>

        <div class="rep-grid">
            <div>
                <div class="rep-label">Пожаловался</div>
                <div class="rep-value">{{ $report->reporter?->name ?? '—' }}</div>
                <div class="rep-muted">{{ $report->reporter?->phone }}</div>
            </div>
            <div>
                <div class="rep-label">На кого</div>
                <div class="rep-value">{{ $target?->name ?? 'Игрок #' . $report->reportable_id }}</div>
                <div class="rep-muted">{{ $target?->phone }}</div>
                @if($blockedByReporter)
                    <div class="rep-flag">уже заблокирован автором жалобы</div>
                @endif
            </div>
        </div>

        @if($report->comment)
            <div style="margin-top:18px;">
                <div class="rep-label">Что написал автор жалобы</div>
                <div class="rep-value">{{ $report->comment }}</div>
            </div>
        @endif
    </div>

    <div class="rep-card">
        <div class="rep-label" style="margin-bottom:10px;">
            Переписка · последние {{ count($messages) }}
        </div>

        @if($messages->isEmpty())
            <div class="rep-muted">
                Переписки между этими игроками нет — жалоба на игрока, а не на сообщения.
            </div>
        @else
            @foreach($messages as $message)
                @php $fromReporter = (int) $message->user_id === (int) $report->reporter_id; @endphp
                <div class="rep-msg">
                    <div class="rep-msg-head">
                        {{ $message->user?->name ?? '—' }}
                        @unless($fromReporter)
                            <span class="rep-msg-accused">· на него жалуются</span>
                        @endunless
                        · {{ $message->created_at?->translatedFormat('j M, H:i') }}
                    </div>
                    <div class="rep-msg-text">{{ $message->text }}</div>
                </div>
            @endforeach
        @endif
    </div>
</div>
@endsection
