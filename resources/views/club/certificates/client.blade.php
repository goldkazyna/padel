@extends('layouts.app')
@section('title', 'Сертификаты клиента')

@section('content')
@include('club.cards._cards_shared_css')
<div class="cc-page">
    <div class="cc-head">
        <h1 class="cc-title">Сертификаты <span class="cc-club">— {{ $client->name }}</span></h1>
        <span class="cc-spacer"></span>
        <a href="{{ route('club.clients.index', ['selected' => $client->id]) }}" class="cc-btn cc-ghost">← К клиенту</a>
        <a href="{{ route('club.certificates.index') }}" class="cc-btn cc-ghost">Все сертификаты</a>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif

    @if($certificates->count() === 0)
        <div class="cc-empty">У этого клиента пока нет сертификатов.</div>
    @else
    <div class="crt-table-wrap">
        <table class="crt-table">
            <thead>
                <tr>
                    <th>Номер</th>
                    <th>Тип</th>
                    <th>Номинал</th>
                    <th>Статус</th>
                    <th>Создан</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($certificates as $c)
                <tr class="{{ $c->isUsed() ? 'crt-row-used' : '' }}">
                    <td class="crt-num">{{ $c->number }}</td>
                    <td><span class="crt-badge {{ $c->isNamed() ? 'crt-named' : 'crt-generic' }}">{{ $c->type_name }}</span></td>
                    <td class="crt-nominal">{{ $c->valueLabel() }}</td>
                    <td>
                        <span class="crt-status {{ $c->isUsed() ? 'crt-st-used' : 'crt-st-active' }}">{{ $c->statusName() }}</span>
                        @if($c->isUsed())<div class="crt-used-at">{{ $c->used_at->format('d.m.Y') }}</div>@endif
                    </td>
                    <td>{{ $c->created_at->format('d.m.Y') }}</td>
                    <td class="crt-actions">
                        <a href="{{ route('club.certificates.show', $c) }}" target="_blank" class="cc-btn cc-ghost cc-sm">Открыть</a>
                        <form method="POST" action="{{ route('club.certificates.redeem', $c) }}" style="display:inline">
                            @csrf
                            @if($c->isUsed())
                                <button type="submit" class="cc-btn cc-ghost cc-sm">Вернуть в активные</button>
                            @else
                                <button type="submit" class="cc-btn cc-green cc-sm">Погасить</button>
                            @endif
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

<style>
.crt-table-wrap { overflow-x:auto; background:#18181b; border:1px solid #27272a; border-radius:12px; }
.crt-table { width:100%; border-collapse:collapse; font-size:.92rem; }
.crt-table th { text-align:left; padding:12px 16px; color:#a1a1aa; font-weight:600; border-bottom:1px solid #27272a; white-space:nowrap; }
.crt-table td { padding:12px 16px; border-bottom:1px solid #1f1f23; color:#e4e4e7; vertical-align:middle; }
.crt-table tr:last-child td { border-bottom:none; }
.crt-row-used td { opacity:.6; }
.crt-num { font-family:monospace; color:#22C55E; }
.crt-nominal { font-weight:700; white-space:nowrap; }
.crt-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.78rem; }
.crt-named { background:rgba(124,58,237,.18); color:#a78bfa; }
.crt-generic { background:rgba(148,163,184,.15); color:#cbd5e1; }
.crt-status { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.78rem; font-weight:700; }
.crt-st-active { background:rgba(34,197,94,.15); color:#22C55E; }
.crt-st-used { background:rgba(148,163,184,.15); color:#94a3b8; }
.crt-used-at { color:#71717a; font-size:.72rem; margin-top:3px; }
.crt-actions { text-align:right; white-space:nowrap; }
.cc-btn.cc-sm { padding:5px 12px; font-size:.82rem; }
</style>
@endsection
