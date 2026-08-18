@extends('layouts.app')

@section('title', 'Аналитика')

@section('content')
<div class="page-header">
    <div>
        <h2>Аналитика</h2>
        <p>Цифры платформы за всё время — по боевым данным</p>
    </div>
</div>

{{-- Итоги --}}
<div class="an-cards">
    @foreach([
        ['Игроков', number_format($totals['players'], 0, '.', ' '), 'people'],
        ['Играли за 30 дней', number_format($totals['active_30d'], 0, '.', ' '), 'activity'],
        ['Турниров проведено', number_format($totals['tournaments'], 0, '.', ' '), 'trophy'],
        ['Участий в турнирах', number_format($totals['participations'], 0, '.', ' '), 'person-check'],
        ['Броней кортов', number_format($totals['bookings'], 0, '.', ' '), 'calendar'],
        ['Выручка с броней', number_format($totals['revenue'], 0, '.', ' ') . ' ₸', 'cash'],
    ] as [$label, $value, $icon])
        <div class="an-card">
            <div class="an-card-label">{{ $label }}</div>
            <div class="an-card-value">{{ $value }}</div>
        </div>
    @endforeach
</div>

{{-- Помесячно --}}
<div class="an-block">
    <h5>По месяцам</h5>
    <div class="an-table-wrap">
        <table class="an-table">
            <thead>
                <tr>
                    <th>Месяц</th>
                    <th>Новых игроков</th>
                    <th>Играли</th>
                    <th>Турниров</th>
                    <th>Участий</th>
                    <th>Броней</th>
                    <th>Выручка</th>
                </tr>
            </thead>
            <tbody>
                @forelse($monthly as $row)
                    <tr>
                        <td class="an-month">{{ $row['month'] }}</td>
                        <td>{{ $row['new_players'] ?: '—' }}</td>
                        <td>{{ $row['active_players'] ?: '—' }}</td>
                        <td>{{ $row['tournaments'] ?: '—' }}</td>
                        <td>{{ $row['participations'] ?: '—' }}</td>
                        <td>{{ $row['bookings'] ?: '—' }}</td>
                        <td>{{ $row['revenue'] ? number_format($row['revenue'], 0, '.', ' ') . ' ₸' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="an-empty">Данных пока нет</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Возвращаемость --}}
<div class="an-block">
    <h5>Возвращаемость</h5>
    <p class="an-hint">
        Из тех, кто впервые сыграл в этом месяце, сколько сыграли ещё хотя бы раз позже.
        Разовый посетитель и постоянный игрок — это два разных бизнеса.
    </p>
    <div class="an-table-wrap">
        <table class="an-table">
            <thead>
                <tr>
                    <th>Месяц</th>
                    <th>Сыграли впервые</th>
                    <th>Вернулись</th>
                    <th>Доля</th>
                </tr>
            </thead>
            <tbody>
                @forelse($retention as $row)
                    <tr>
                        <td class="an-month">{{ $row['month'] }}</td>
                        <td>{{ $row['first_time'] }}</td>
                        <td>{{ $row['returned'] }}</td>
                        <td>
                            <span class="an-share {{ $row['share'] >= 50 ? 'is-good' : ($row['share'] >= 25 ? 'is-mid' : 'is-low') }}">
                                {{ $row['share'] }}%
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="an-empty">Данных пока нет</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- По клубам --}}
<div class="an-block">
    <h5>По клубам</h5>
    <div class="an-table-wrap">
        <table class="an-table">
            <thead>
                <tr>
                    <th>Клуб</th>
                    <th>Турниров</th>
                    <th>Участий</th>
                    <th>Броней</th>
                    <th>Выручка</th>
                </tr>
            </thead>
            <tbody>
                @forelse($byClub as $row)
                    <tr>
                        <td class="an-club">{{ $row['club'] }}</td>
                        <td>{{ $row['tournaments'] ?: '—' }}</td>
                        <td>{{ $row['participations'] ?: '—' }}</td>
                        <td>{{ $row['bookings'] ?: '—' }}</td>
                        <td>{{ $row['revenue'] ? number_format($row['revenue'], 0, '.', ' ') . ' ₸' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="an-empty">Клубов с активностью пока нет</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="an-note">
    <b>Что здесь не учтено.</b> Открытия приложения и нажатия на кнопки не собираются —
    в приложении нет аналитики, и задним числом этих цифр не существует.
    Журнал входов ловит только веб-панель клуба, поэтому в отчёт не идёт.
    Всё, что выше, посчитано по турнирам, участиям и броням — они есть с первого дня.
</div>

<style>
.an-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 26px;
}
.an-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px 18px;
}
.an-card-label {
    font-size: 11px;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--text-muted);
    font-weight: 700;
    margin-bottom: 8px;
}
.an-card-value {
    font-size: 24px;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.1;
}
.an-block { margin-bottom: 30px; }
.an-block h5 {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 6px;
}
.an-hint {
    color: var(--text-muted);
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 12px;
    max-width: 640px;
}
.an-table-wrap {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow-x: auto;
}
.an-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
    white-space: nowrap;
}
.an-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 11px;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--text-muted);
    font-weight: 700;
    border-bottom: 1px solid var(--border);
}
.an-table td {
    padding: 12px 16px;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border);
}
.an-table tr:last-child td { border-bottom: none; }
.an-month, .an-club { color: var(--text-primary); font-weight: 600; }
.an-empty { color: var(--text-muted); text-align: center; padding: 30px; }
.an-share {
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 20px;
}
.an-share.is-good { background: rgba(34,197,94,.16); color: #22c55e; }
.an-share.is-mid { background: rgba(234,179,8,.16); color: #eab308; }
.an-share.is-low { background: rgba(156,163,175,.14); color: var(--text-muted); }
.an-note {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px 18px;
    color: var(--text-muted);
    font-size: 13px;
    line-height: 1.6;
}
.an-note b { color: var(--text-secondary); }
</style>
@endsection
