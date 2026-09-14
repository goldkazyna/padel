@extends('layouts.app')

@section('title', 'Платежи из приложения')

@section('content')
@php
    $tz = \App\Models\Shift::TZ;

    $statusNames = [
        'paid' => 'Оплачен',
        'authorized' => 'Холд',
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

    // Возврат — деньги наружу: только администратор клуба, не менеджер.
    $canRefund = auth()->user()->isSuperAdmin()
        || auth()->user()->adminClubs()->where('clubs.id', $club->id)->exists();

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
    /* Холд: деньги придержаны, но ещё не списаны — синим, чтобы не путать
       ни с оплатой, ни с зависшим платежом. */
    .apay-authorized { background: rgba(59, 130, 246, .16); color: #60a5fa; }
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
                            @if($canRefund)<th></th>@endif
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
                                @if($canRefund)
                                    <td style="text-align: right; white-space: nowrap;">
                                        {{-- Вернуть можно только прошедший платёж: по остальным
                                             шлюз всё равно откажет. --}}
                                        @if(in_array($row['status'], ['paid', 'authorized'], true) && $row['id'])
                                            <button type="button" class="apay-refund-btn"
                                                    onclick="openRefund(this)"
                                                    data-id="{{ $row['id'] }}"
                                                    data-amount="{{ (int) $row['amount'] }}"
                                                    data-hold="{{ $row['status'] === 'authorized' ? '1' : '' }}"
                                                    data-title="{{ $row['title'] }}{{ $row['subtitle'] ? ' · ' . $row['subtitle'] : '' }}">
                                                {{ $row['status'] === 'authorized' ? 'Снять холд' : 'Возврат' }}
                                            </button>
                                        @endif
                                    </td>
                                @endif
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

@if($canRefund)
    {{-- Окно возврата. Сумма подставляется полная, но её можно уменьшить:
         шлюз умеет возвращать часть. --}}
    <div class="apay-modal-overlay" id="refundOverlay" onclick="if(event.target === this) closeRefund()">
        <form method="POST" id="refundForm" class="apay-modal">
            @csrf
            <div class="apay-modal-title">Возврат средств</div>
            <div class="apay-modal-sub" id="refundSubject"></div>

            <label class="apay-modal-label">Сумма возврата, ₸</label>
            <input type="number" name="amount" id="refundAmount" class="apay-modal-input"
                   min="1" step="1" required>
            <div class="apay-modal-hint" id="refundHint"></div>

            <label class="apay-modal-label">Причина (необязательно)</label>
            <input type="text" name="reason" class="apay-modal-input" maxlength="255"
                   placeholder="Например: клиент отменил бронь">

            <div class="apay-modal-warn">
                Деньги уйдут клиенту на карту. Отменить возврат нельзя.
            </div>

            <div class="apay-modal-actions">
                <button type="button" class="apay-modal-btn apay-modal-ghost" onclick="closeRefund()">Отмена</button>
                <button type="submit" class="apay-modal-btn apay-modal-danger">Вернуть деньги</button>
            </div>
        </form>
    </div>

    <style>
        .apay-refund-btn {
            background: transparent;
            border: 1px solid rgba(239, 68, 68, .45);
            color: #f87171;
            border-radius: 8px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .apay-refund-btn:hover { background: rgba(239, 68, 68, .12); border-color: #ef4444; }

        .apay-modal-overlay {
            display: none;
            position: fixed; inset: 0; z-index: 1000;
            background: rgba(0, 0, 0, .6);
            padding: 20px;
            overflow-y: auto;
        }
        .apay-modal-overlay.open { display: flex; align-items: center; justify-content: center; }
        .apay-modal {
            width: 100%; max-width: 420px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px 24px;
        }
        .apay-modal-title { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); }
        .apay-modal-sub { color: var(--text-secondary); font-size: .82rem; margin: 6px 0 16px; }
        .apay-modal-label {
            display: block; color: var(--text-secondary);
            font-size: .74rem; font-weight: 700; letter-spacing: .4px;
            text-transform: uppercase; margin-bottom: 6px;
        }
        .apay-modal-input {
            width: 100%; padding: 11px 13px; margin-bottom: 14px;
            background: var(--bg-dark); border: 1px solid var(--border);
            border-radius: 10px; color: var(--text-primary); font-size: .95rem;
        }
        .apay-modal-hint { color: var(--text-secondary); font-size: .76rem; margin: -10px 0 14px; }
        .apay-modal-warn {
            background: rgba(239, 68, 68, .1);
            border: 1px solid rgba(239, 68, 68, .3);
            color: #fca5a5;
            border-radius: 10px; padding: 10px 12px;
            font-size: .8rem; line-height: 1.4; margin-bottom: 16px;
        }
        .apay-modal-actions { display: flex; gap: 10px; }
        .apay-modal-btn {
            flex: 1; padding: 12px; border-radius: 10px;
            font-size: .9rem; font-weight: 700; cursor: pointer; border: none;
        }
        .apay-modal-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-secondary); }
        .apay-modal-danger { background: #ef4444; color: #fff; }
        .apay-modal-danger:hover { background: #dc2626; }
    </style>

    <script>
        const REFUND_URL = '{{ url('club/payments/app') }}';

        function openRefund(btn) {
            const amount = btn.dataset.amount;
            const hold = btn.dataset.hold === '1';
            document.getElementById('refundForm').action = REFUND_URL + '/' + btn.dataset.id + '/refund';
            document.getElementById('refundSubject').textContent = btn.dataset.title;
            document.getElementById('refundAmount').value = amount;
            document.getElementById('refundAmount').max = amount;

            // Холд — деньги ещё не списаны, их отпускают целиком: объясняем
            // это прямо в окне, иначе «возврат» и «снятие холда» путают.
            document.querySelector('.apay-modal-title').textContent =
                hold ? 'Снять холд' : 'Возврат средств';
            document.querySelector('.apay-modal-warn').textContent = hold
                ? 'Деньги придержаны у клиента, но не списаны. Холд снимется, сумма вернётся на карту.'
                : 'Деньги уйдут клиенту на карту. Отменить возврат нельзя.';
            document.querySelector('.apay-modal-danger').textContent =
                hold ? 'Снять холд' : 'Вернуть деньги';
            document.getElementById('refundHint').textContent = hold
                ? 'Придержано ' + Number(amount).toLocaleString('ru-RU') + ' ₸.'
                : 'Оплачено ' + Number(amount).toLocaleString('ru-RU') + ' ₸. Можно вернуть часть.';
            document.getElementById('refundOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeRefund() {
            document.getElementById('refundOverlay').classList.remove('open');
            document.body.style.overflow = '';
        }

        document.getElementById('refundForm').addEventListener('submit', function (e) {
            const btn = this.querySelector('.apay-modal-danger');
            btn.disabled = true;
            btn.textContent = 'Отправляем…';
        });
    </script>
@endif
@endsection
