@extends('layouts.app')
@section('title', 'К списанию')

@section('content')
<div class="cards-page">
    <div class="cards-header">
        <h1 class="cards-title">К списанию <span class="cards-title-club">— {{ $club->name }}</span></h1>
        <div class="cards-header-actions">
            <a href="{{ route('club.cards.index') }}" class="btn-journal">← Клубные карты</a>
        </div>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif

    @if($bookings->isEmpty())
        <div class="cards-empty">Нет броней к списанию.</div>
    @else
    <table class="table" style="width:100%;border-collapse:collapse">
        <thead>
            <tr style="text-align:left">
                <th style="padding:8px">Клиент</th>
                <th style="padding:8px">Карта</th>
                <th style="padding:8px">Дата</th>
                <th style="padding:8px">Время</th>
                <th style="padding:8px">Часов</th>
                <th style="padding:8px">Остаток</th>
                <th style="padding:8px">Действие</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bookings as $b)
            @php
                $card = $b->clubCard;
                $hours = (int) round(max(0, \Carbon\Carbon::parse(substr($b->start_time,0,5))->diffInMinutes(\Carbon\Carbon::parse(substr($b->end_time,0,5)))) / 60);
            @endphp
            <tr style="border-top:1px solid #2a2a2a">
                <td style="padding:8px">{{ $card?->client?->name ?? $b->client_name }}</td>
                <td style="padding:8px">{{ $card?->code }} <span style="color:#888">{{ $card?->type?->name }}</span></td>
                <td style="padding:8px">{{ \Carbon\Carbon::parse($b->date)->format('d.m.Y') }}</td>
                <td style="padding:8px">{{ substr($b->start_time,0,5) }}–{{ substr($b->end_time,0,5) }}</td>
                <td style="padding:8px">{{ $hours }} ч</td>
                <td style="padding:8px">{{ $card?->balance }}</td>
                <td style="padding:8px;white-space:nowrap">
                    <form action="{{ route('club.cards.pending.charge', $b) }}" method="POST" class="d-inline">
                        @csrf
                        <button class="btn-add" type="submit" onclick="return confirm('Списать {{ $hours }} ч с карты {{ $card?->code }}?')">Списать</button>
                    </form>
                    <form action="{{ route('club.cards.pending.skip', $b) }}" method="POST" class="d-inline">
                        @csrf
                        <button class="btn-journal" type="submit" onclick="return confirm('Пометить без списания?')">Не списывать</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

<style>
.cards-page { max-width: 980px; }
.cards-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
.cards-title { font-size:24px; font-weight:800; color:#fff; margin:0; }
.cards-title-club { color:#71717a; font-weight:500; font-size:16px; }
.cards-header-actions { display:flex; gap:10px; align-items:center; }
.btn-journal { background:#27272a; color:#d4d4d8; border:none; border-radius:10px; padding:10px 16px; font-weight:700; text-decoration:none; }
.btn-add { background:#22c55e; color:#fff; border:none; border-radius:10px; padding:10px 16px; font-weight:700; cursor:pointer; }
.flash-message { padding:10px 14px; border-radius:10px; margin-bottom:14px; }
.flash-success { background:rgba(34,197,94,.12); color:#22c55e; border:1px solid rgba(34,197,94,.3); }
.flash-error { background:rgba(239,68,68,.12); color:#ef4444; border:1px solid rgba(239,68,68,.3); }
.cards-empty { color:#71717a; padding:24px; text-align:center; background:#18181b; border:1px solid #27272a; border-radius:12px; }
.table th { color:#a1a1aa; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; background:#18181b; }
.table td { color:#d4d4d8; font-size:14px; }
</style>
@endsection
