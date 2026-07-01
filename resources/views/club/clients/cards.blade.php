@extends('layouts.app')
@section('title', 'Клубные карты')

@section('content')
<div class="ov-wrap">
    <div class="ov-head">
        <a href="{{ route('club.clients.index') }}" class="ov-back"><i class="bi bi-arrow-left"></i></a>
        <h1>Клубные карты</h1>
    </div>

    <div class="ov-tabs">
        @foreach([['all','Все'],['ending','Заканчиваются'],['ended','Закончились']] as [$key,$label])
            <a href="{{ route('club.clients.cards', ['f' => $key]) }}"
               class="ov-tab {{ $f === $key ? 'active' : '' }} {{ $key }}">
                {{ $label }} <span class="ov-n">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </div>

    <div class="ov-list">
        @forelse($rows as $r)
            <div class="ov-item">
                <div class="ov-main">
                    <div class="ov-name">{{ $r['client_name'] }}</div>
                    <div class="ov-sub">{{ $r['type_name'] }}</div>
                </div>
                <div class="ov-meta">
                    @if($r['counter'])
                        <span class="ov-bal">{{ $r['balance'] }} / {{ $r['initial'] }}</span>
                    @endif
                    <span class="ov-date">{{ $r['expires'] ?? 'бессрочно' }}</span>
                    @php
                        $badge = $r['expired'] ? ['Просрочена','red'] : ($r['used'] ? ['Использована','red']
                            : ($r['soon'] ? ['Истекает','amber'] : ($r['low'] ? ['Мало визитов','amber'] : ['Активна','green'])));
                    @endphp
                    <span class="ov-badge {{ $badge[1] }}">{{ $badge[0] }}</span>
                </div>
            </div>
        @empty
            <div class="ov-empty">Ничего не найдено</div>
        @endforelse
    </div>
</div>

@include('club.clients._overview_styles')
@endsection
