@extends('layouts.app')

@php
    // Причины жалоб человеческим языком. Массивом, а не функцией: функция в
    // Blade объявляется глобально и падает при повторном рендере вида.
    $reasonNames = [
        'spam' => 'Спам и реклама',
        'abuse' => 'Оскорбления',
        'fraud' => 'Мошенничество',
        'other' => 'Другое',
    ];
@endphp

@section('content')
<style>
    .rep-wrap { max-width: 1200px; }
    .rep-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .rep-head h1 { font-size: 24px; font-weight: 700; margin: 0; color: var(--text-primary); }

    .rep-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
    .rep-tab {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 8px 14px; border-radius: 10px;
        border: 1px solid var(--border);
        color: var(--text-secondary); text-decoration: none;
        font-size: 13px; font-weight: 600;
    }
    .rep-tab:hover { color: var(--text-primary); border-color: var(--border-light); }
    .rep-tab.active { background: var(--accent); border-color: var(--accent); color: #0c0e0f; }
    .rep-tab-n { opacity: .7; font-size: 12px; }

    .rep-table-wrap {
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 16px;
        overflow: hidden;
    }
    .rep-table { width: 100%; border-collapse: collapse; }
    .rep-table th {
        padding: 14px 20px; text-align: left;
        font-size: 12px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
        color: var(--text-muted);
        background: var(--bg-card);
        border-bottom: 1px solid var(--border);
    }
    .rep-table td { padding: 14px 20px; border-bottom: 1px solid var(--border); color: var(--text-primary); font-size: 14px; }
    .rep-table tr:last-child td { border-bottom: 0; }
    .rep-table tr:hover td { background: var(--bg-card-hover); }
    .rep-sub { color: var(--text-muted); font-size: 12px; margin-top: 2px; }
    .rep-when { color: var(--text-secondary); font-size: 13px; white-space: nowrap; }

    .rep-badge {
        display: inline-block; padding: 4px 9px; border-radius: 6px;
        font-size: 11px; font-weight: 700;
        background: rgba(156, 163, 175, .15); color: var(--text-secondary);
    }
    .rep-badge-new { background: rgba(245, 158, 11, .16); color: #f59e0b; margin-left: 6px; }

    .rep-open {
        display: inline-block; padding: 7px 14px; border-radius: 10px;
        border: 1px solid var(--border-light);
        color: var(--text-primary); text-decoration: none;
        font-size: 13px; font-weight: 600;
    }
    .rep-open:hover { border-color: var(--accent); color: var(--accent); }

    .rep-empty {
        padding: 48px 20px; text-align: center; color: var(--text-secondary);
        background: var(--bg-secondary);
        border: 1px solid var(--border); border-radius: 16px;
    }
</style>

<div class="rep-wrap">
    <div class="rep-head">
        <h1>Жалобы игроков</h1>
    </div>

    <div class="rep-tabs">
        @php $tabs = [
            [\App\Models\ContentReport::STATUS_NEW, 'Новые', $counts['new']],
            [\App\Models\ContentReport::STATUS_REVIEWED, 'Разобранные', $counts['reviewed']],
            ['all', 'Все', $counts['new'] + $counts['reviewed']],
        ]; @endphp
        @foreach($tabs as [$key, $label, $n])
            <a href="{{ route('admin.reports.index', ['status' => $key]) }}"
               class="rep-tab {{ $status === $key ? 'active' : '' }}">
                {{ $label }} <span class="rep-tab-n">{{ $n }}</span>
            </a>
        @endforeach
    </div>

    @if($reports->isEmpty())
        <div class="rep-empty">Жалоб нет. Это хорошая новость.</div>
    @else
        <div class="rep-table-wrap">
            <table class="rep-table">
                <thead>
                    <tr>
                        <th>Когда</th>
                        <th>Кто пожаловался</th>
                        <th>На кого</th>
                        <th>Причина</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $report)
                        @php $target = $targets[$report->reportable_id] ?? null; @endphp
                        <tr>
                            <td class="rep-when">{{ $report->created_at?->translatedFormat('j M, H:i') }}</td>
                            <td>
                                {{ $report->reporter?->name ?? '—' }}
                                <div class="rep-sub">{{ $report->reporter?->phone }}</div>
                            </td>
                            <td>
                                {{ $target?->name ?? 'Игрок #' . $report->reportable_id }}
                                <div class="rep-sub">{{ $target?->phone }}</div>
                            </td>
                            <td>
                                <span class="rep-badge">{{ $reasonNames[$report->reason] ?? $report->reason }}</span>
                                @if($report->status === \App\Models\ContentReport::STATUS_NEW)
                                    <span class="rep-badge rep-badge-new">новая</span>
                                @endif
                            </td>
                            <td style="text-align: right;">
                                <a href="{{ route('admin.reports.show', $report) }}" class="rep-open">Открыть</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $reports->links() }}</div>
    @endif
</div>
@endsection
