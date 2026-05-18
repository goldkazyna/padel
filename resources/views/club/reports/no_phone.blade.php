@extends('layouts.app')

@section('title', 'Брони без телефона')

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
        <h1 style="font-size:22px;font-weight:800;margin:0;">Брони без телефона</h1>
        <span style="background:rgba(249,115,22,0.15);color:#f97316;padding:4px 12px;border-radius:20px;font-weight:700;font-size:12px;">
            всего {{ $totalCount }}
        </span>
        <div style="flex:1;"></div>
        <div style="color:#71717a;font-size:13px;">{{ $club->name }}</div>
    </div>

    @if($totalCount === 0)
        <div style="background:#16161a;border:1px solid #27272a;border-radius:16px;padding:80px 20px;text-align:center;color:#71717a;">
            <i class="bi bi-check2-circle" style="font-size:56px;color:#22c55e;margin-bottom:16px;display:block;"></i>
            <p style="font-size:16px;margin:0;">Все брони с телефонами</p>
        </div>
    @else
        <div style="background:#16161a;border:1px solid #27272a;border-radius:14px;overflow:hidden;">
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
                                <td style="padding:12px 14px;font-size:13px;color:#f4f4f5;font-weight:600;white-space:nowrap;">{{ $b->date->format('d.m.Y') }}</td>
                                <td style="padding:12px 14px;font-size:13px;color:#a1a1aa;white-space:nowrap;">{{ substr($b->start_time, 0, 5) }}–{{ substr($b->end_time, 0, 5) }}</td>
                                <td style="padding:12px 14px;font-size:13px;color:#f4f4f5;">{{ $b->client_name ?: '— без имени' }}</td>
                                <td style="padding:12px 14px;font-size:13px;color:#a1a1aa;">{{ $b->court->name ?? '—' }}</td>
                                <td style="padding:12px 14px;font-size:13px;color:#f4f4f5;text-align:right;font-weight:600;white-space:nowrap;">{{ $formatMoney($b->price) }}</td>
                                <td style="padding:12px 14px;text-align:center;">
                                    @if($b->is_paid)
                                        <span style="background:rgba(34,197,94,0.15);color:#22c55e;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">оплачено</span>
                                    @else
                                        <span style="background:rgba(249,115,22,0.15);color:#f97316;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">не опл.</span>
                                    @endif
                                </td>
                                <td style="padding:12px 14px;text-align:right;">
                                    <a href="{{ route('club.courts.schedule', ['date' => $b->date->format('Y-m-d')]) }}"
                                       style="display:inline-flex;align-items:center;gap:4px;color:#3b82f6;text-decoration:none;font-size:12px;font-weight:600;white-space:nowrap;"
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
    @endif
</div>
@endsection
