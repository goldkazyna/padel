@extends('layouts.app')

@section('title', 'Платежи из приложения')

@section('content')
@php
    $tz = \App\Models\Shift::TZ;

    $statusNames = [
        'paid' => 'Оплачен',
        'pending' => 'В процессе',
        'refunded' => 'Возврат',
        'failed' => 'Не прошёл',
        'unknown' => 'Неизвестно',
    ];

    $kindNames = [
        'booking' => 'Бронь',
        'paylink' => 'Счёт',
        'tournament' => 'Турнир',
        'external' => 'Вне приложения',
    ];

    $paidSum = collect($rows)->where('status', 'paid')->sum('amount');
    $paidCount = collect($rows)->where('status', 'paid')->count();
@endphp

<style>
    .apay-wrap { max-width: 1200px; }
    .apay-head { margin-bottom: 18px; }
    .apay-head h2 { font-size: 24px; font-weight: 700; margin: 0 0 4px; color: var(--text-primary); }
    .apay-head p { color: var(--text-secondary); font-size: 14px; margin: 0; }

    .apay-tabs { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
    .apay-tab {
        padding: 9px 16px; border-radius: 10px; border: 1px solid var(--border);
        color: var(--text-secondary); text-decoration: none; font-size: 13px; font-weight: 600;
    }
    .apay-tab:hover { color: var(--text-primary); border-color: var(--border-light); }
    .apay-tab.active { background: var(--accent); border-color: var(--accent); color: #0c0e0f; }

    .apay-sum {
        display: flex; gap: 20px; flex-wrap: wrap; align-items: center;
        background: var(--bg-secondary); border: 1px solid var(--border);
        border-radius: 14px; padding: 16px 20px; margin-bottom: 18px;
    }
    .apay-sum b { display: block; font-size: 20px; font-weight: 800; color: var(--text-primary); }
    .apay-sum span { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; }
    .apay-refresh {
        margin-left: auto; padding: 9px 16px; border-radius: 10px;
        border: 1px solid var(--border-light); color: var(--text-primary);
        text-decoration: none; font-size: 13px; font-weight: 600;
    }
    .apay-refresh:hover { border-color: var(--accent); color: var(--accent); }

    .apay-table-wrap {
        background: var(--bg-secondary); border: 1px solid var(--border);
        border-radius: 16px; overflow: hidden;
    }
    .apay-table { width: 100%; border-collapse: collapse; }
    .apay-table th {
        padding: 14px 20px; text-align: left; font-size: 12px; font-weight: 700;
        letter-spacing: .5px; text-transform: uppercase; color: var(--text-muted);
        background: var(--bg-card); border-bottom: 1px solid var(--border);
    }
    .apay-table td {
        padding: 14px 20px; border-bottom: 1px solid var(--border);
        color: var(--text-primary); font-size: 14px; vertical-align: top;
    }
    .apay-table tr:last-child td { border-bottom: 0; }
    .apay-table tr:hover td { background: var(--bg-card-hover); }
    .apay-sub { color: var(--text-muted); font-size: 12px; margin-top: 2px; }
    .apay-amount { font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }

    .apay-badge { display: inline-block; padding: 4px 9px; border-radius: 6px; font-size: 11px; font-weight: 700; }
    .apay-paid { background: rgba(34, 197, 94, .16); color: #22c55e; }
    .apay-pending { background: rgba(245, 158, 11, .16); color: #f59e0b; }
    .apay-failed { background: rgba(239, 68, 68, .14); color: #ef4444; }
    .apay-refunded { background: rgba(156, 163, 175, .16); color: var(--text-secondary); }
    .apay-unknown { background: rgba(156, 163, 175, .16); color: var(--text-secondary); }

    .apay-kind { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700;
        background: rgba(74, 139, 245, .14); color: #4a8bf5; }
    .apay-kind-external { background: rgba(156, 163, 175, .16); color: var(--text-secondary); }

    .apay-empty {
        padding: 48px 20px; text-align: center; color: var(--text-secondary);
        background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 16px;
    }
    .apay-error {
        padding: 16px 20px; border-radius: 14px; margin-bottom: 18px;
        background: rgba(239, 68, 68, .1); border: 1px solid rgba(239, 68, 68, .3); color: #ef4444;
        font-size: 14px;
    }
    .apay-pager { display: flex; gap: 8px; margin-top: 16px; }
    .apay-pager a, .apay-pager span {
        padding: 8px 14px; border-radius: 10px; border: 1px solid var(--border);
        color: var(--text-secondary); text-decoration: none; font-size: 13px;
    }
    .apay-pager span { opacity: .4; }
</style>

<div class="apay-wrap">
    <div class="apay-head">
        <h2>Платежи</h2>
        <p>{{ $club->name }} · всё, за что заплатили картой</p>
    </div>

    <div class="apay-tabs">
        <a href="{{ route('club.payments.index') }}" class="apay-tab">Счета клиентам</a>
        <a href="{{ route('club.payments.app') }}" class="apay-tab active">Все платежи</a>
    </div>

    @if(!$club->hasPlexyConfigured())
        <div class="apay-empty">
            <b>Онлайн-оплата не настроена.</b><br>
            Чтобы видеть платежи, супер-админ должен указать ключи Plexy в настройках клуба.
        </div>
    @else
        @if($error)
            <div class="apay-error">Не удалось получить платежи от Plexy: {{ $error }}</div>
        @endif

        <div class="apay-sum">
            <div>
                <b>{{ number_format($paidSum, 0, '.', ' ') }} ₸</b>
                <span>оплачено на этой странице</span>
            </div>
            <div>
                <b>{{ $paidCount }}</b>
                <span>успешных платежей</span>
            </div>
            <div>
                <b>{{ $total }}</b>
                <span>всего транзакций</span>
            </div>
            <a href="{{ route('club.payments.app', ['refresh' => 1, 'page' => $page]) }}" class="apay-refresh">
                Обновить
            </a>
        </div>

        @if(empty($rows))
            <div class="apay-empty">Платежей пока нет.</div>
        @else
            <div class="apay-table-wrap">
                <table class="apay-table">
                    <thead>
                        <tr>
                            <th>Когда</th>
                            <th>За что</th>
                            <th>Сумма</th>
                            <th>Статус</th>
                            <th>RRN</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td style="white-space: nowrap;">
                                    {{ $row['created_at']?->timezone($tz)->format('d.m.Y') }}
                                    <div class="apay-sub">{{ $row['created_at']?->timezone($tz)->format('H:i') }}</div>
                                </td>
                                <td>
                                    <span class="apay-kind {{ $row['kind'] === 'external' ? 'apay-kind-external' : '' }}">
                                        {{ $kindNames[$row['kind']] ?? $row['kind'] }}
                                    </span>
                                    @if($row['url'])
                                        <a href="{{ $row['url'] }}" style="color: var(--text-primary); text-decoration: none;">
                                            {{ $row['title'] }}
                                        </a>
                                    @else
                                        {{ $row['title'] }}
                                    @endif
                                    @if($row['subtitle'])
                                        <div class="apay-sub">{{ $row['subtitle'] }}</div>
                                    @endif
                                </td>
                                <td class="apay-amount">{{ number_format($row['amount'], 0, '.', ' ') }} ₸</td>
                                <td>
                                    <span class="apay-badge apay-{{ $row['status'] }}">
                                        {{ $statusNames[$row['status']] ?? $row['status'] }}
                                    </span>
                                </td>
                                <td class="apay-sub" style="padding-top: 16px;">{{ $row['rrn'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @php $lastPage = (int) ceil(max($total, 1) / max($size, 1)); @endphp
            @if($lastPage > 1)
                <div class="apay-pager">
                    @if($page > 1)
                        <a href="{{ route('club.payments.app', ['page' => $page - 1]) }}">← Назад</a>
                    @else
                        <span>← Назад</span>
                    @endif
                    <span>Страница {{ $page }} из {{ $lastPage }}</span>
                    @if($page < $lastPage)
                        <a href="{{ route('club.payments.app', ['page' => $page + 1]) }}">Вперёд →</a>
                    @else
                        <span>Вперёд →</span>
                    @endif
                </div>
            @endif
        @endif
    @endif
</div>
@endsection
