@extends('layouts.app')

@section('title', 'Неразобранные брони')

@php
    $formatMoney = fn($v) => number_format((float) $v, 0, '.', ' ') . ' ₸';
@endphp

@section('content')
<div class="container-fluid" style="padding:20px;max-width:1400px;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="{{ route('club.reports.index') }}"
           style="width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;background:#16161a;border:1px solid #27272a;border-radius:10px;color:#a1a1aa;text-decoration:none;">
            <i class="bi bi-arrow-left"></i>
        </a>
        <i class="bi bi-exclamation-triangle-fill" style="font-size:22px;color:#f97316;"></i>
        <h1 style="font-size:22px;font-weight:800;margin:0;">Неразобранные брони</h1>
        <span style="background:rgba(249,115,22,0.15);color:#f97316;padding:4px 12px;border-radius:20px;font-weight:700;font-size:12px;">
            всего {{ $totalCount }}
        </span>
        <div style="flex:1;"></div>
        <div style="color:#71717a;font-size:13px;">{{ $club->name }}</div>
    </div>

    <div style="background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.3);color:#cbd5e1;padding:12px 16px;border-radius:10px;font-size:13px;line-height:1.5;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;">
        <i class="bi bi-info-circle" style="color:#3b82f6;font-size:16px;flex-shrink:0;margin-top:1px;"></i>
        <span>
            Это <strong>брони</strong>, у которых в записи не указан телефон клиента — старые брони, созданные до того, как телефон стал обязательным.
            Откройте день в расписании, чтобы дозаполнить телефон в брони.
        </span>
    </div>

    {{-- Summary --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;">
        <div style="background:#16161a;border:1px solid #27272a;border-radius:12px;padding:14px 16px;">
            <div style="color:#71717a;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Всего броней</div>
            <div style="font-size:22px;font-weight:800;color:#f4f4f5;">{{ $totalCount }}</div>
        </div>
        <div style="background:#16161a;border:1px solid #27272a;border-radius:12px;padding:14px 16px;">
            <div style="color:#71717a;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Уникальных клиентов</div>
            <div style="font-size:22px;font-weight:800;color:#f4f4f5;">{{ $uniqueClients }}</div>
        </div>
        <div style="background:#16161a;border:1px solid #27272a;border-radius:12px;padding:14px 16px;">
            <div style="color:#71717a;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Сумма броней</div>
            <div style="font-size:22px;font-weight:800;color:#f4f4f5;">{{ $formatMoney($totalSum) }}</div>
        </div>
        <div style="background:#16161a;border:1px solid #27272a;border-radius:12px;padding:14px 16px;">
            <div style="color:#71717a;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Период</div>
            <div style="font-size:14px;font-weight:700;color:#f4f4f5;">
                @if($earliest && $latest)
                    {{ \Carbon\Carbon::parse($earliest)->format('d.m.Y') }} — {{ \Carbon\Carbon::parse($latest)->format('d.m.Y') }}
                @else
                    —
                @endif
            </div>
        </div>
    </div>

    @if($totalCount === 0)
        <div style="background:#16161a;border:1px solid #27272a;border-radius:16px;padding:80px 20px;text-align:center;color:#71717a;">
            <i class="bi bi-check2-circle" style="font-size:56px;color:#22c55e;margin-bottom:16px;display:block;"></i>
            <p style="font-size:16px;margin:0;">Все брони с телефонами — неразобранных нет</p>
        </div>
    @else

        {{-- Топ имён --}}
        @if($uniqueClients > 1)
        <div style="background:#16161a;border:1px solid #27272a;border-radius:14px;padding:18px;margin-bottom:24px;">
            <div style="font-weight:800;font-size:15px;margin-bottom:12px;color:#f4f4f5;">Кто чаще всего без телефона</div>
            <div style="display:flex;flex-direction:column;gap:6px;">
                @foreach($byName->take(15) as $name => $rows)
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#1c1c21;border-radius:8px;">
                        <div style="font-weight:600;color:#f4f4f5;font-size:14px;">{{ $name }}</div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <span style="background:rgba(249,115,22,0.15);color:#f97316;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;">
                                {{ $rows->count() }} {{ trans_choice('бронь|брони|броней', $rows->count()) }}
                            </span>
                            <span style="color:#71717a;font-size:12px;font-weight:600;">{{ $formatMoney($rows->sum('price')) }}</span>
                        </div>
                    </div>
                @endforeach
                @if($byName->count() > 15)
                    <div style="text-align:center;color:#71717a;font-size:12px;padding-top:8px;">… и ещё {{ $byName->count() - 15 }} клиентов</div>
                @endif
            </div>
        </div>
        @endif

        {{-- Список броней --}}
        <div style="background:#16161a;border:1px solid #27272a;border-radius:14px;overflow:hidden;margin-bottom:16px;">
            <div style="padding:16px 18px;border-bottom:1px solid #27272a;display:flex;justify-content:space-between;align-items:center;">
                <div style="font-weight:800;font-size:15px;color:#f4f4f5;">Список броней</div>
                <div style="color:#71717a;font-size:12px;">
                    {{ $bookings->firstItem() }}–{{ $bookings->lastItem() }} из {{ $bookings->total() }}
                </div>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#1c1c21;">
                            <th style="text-align:left;padding:12px 14px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#71717a;font-weight:700;">Дата</th>
                            <th style="text-align:left;padding:12px 14px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#71717a;font-weight:700;">Время</th>
                            <th style="text-align:left;padding:12px 14px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#71717a;font-weight:700;">Клиент</th>
                            <th style="text-align:left;padding:12px 14px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#71717a;font-weight:700;">Корт</th>
                            <th style="text-align:right;padding:12px 14px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#71717a;font-weight:700;">Сумма</th>
                            <th style="text-align:center;padding:12px 14px;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#71717a;font-weight:700;">Оплата</th>
                            <th style="padding:12px 14px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bookings as $b)
                            <tr style="border-top:1px solid #27272a;">
                                <td style="padding:12px 14px;font-size:13px;color:#f4f4f5;font-weight:600;">{{ \Carbon\Carbon::parse($b->date)->format('d.m.Y') }}</td>
                                <td style="padding:12px 14px;font-size:13px;color:#a1a1aa;">{{ substr($b->start_time, 0, 5) }}–{{ substr($b->end_time, 0, 5) }}</td>
                                <td style="padding:12px 14px;font-size:13px;color:#f4f4f5;">{{ $b->client_name ?: '— без имени' }}</td>
                                <td style="padding:12px 14px;font-size:13px;color:#a1a1aa;">{{ $b->court->name ?? '—' }}</td>
                                <td style="padding:12px 14px;font-size:13px;color:#f4f4f5;text-align:right;font-weight:600;">{{ $formatMoney($b->price) }}</td>
                                <td style="padding:12px 14px;text-align:center;">
                                    @if($b->is_paid)
                                        <span style="background:rgba(34,197,94,0.15);color:#22c55e;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">оплачено</span>
                                    @else
                                        <span style="background:rgba(249,115,22,0.15);color:#f97316;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">не опл.</span>
                                    @endif
                                </td>
                                <td style="padding:12px 14px;text-align:right;">
                                    <a href="{{ route('club.courts.schedule', ['date' => $b->date]) }}"
                                       style="display:inline-flex;align-items:center;gap:4px;color:#3b82f6;text-decoration:none;font-size:12px;font-weight:600;"
                                       title="Открыть день в расписании">
                                        Открыть <i class="bi bi-arrow-up-right"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if($bookings->hasPages())
            <div style="display:flex;justify-content:center;">
                {{ $bookings->links() }}
            </div>
        @endif

    @endif
</div>
@endsection
