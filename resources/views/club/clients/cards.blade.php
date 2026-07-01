@extends('layouts.app')
@section('title', 'Клубные карты')

@section('content')
<div class="ov-wrap">
    <div class="ov-head">
        <a href="{{ route('club.clients.index') }}" class="ov-back"><i class="bi bi-arrow-left"></i></a>
        <h1>Клубные карты</h1>
    </div>

    <div class="ov-tabs">
        @foreach([['all','Все'],['ending','Заканчиваются'],['ended','Закончились'],['archive','Архив']] as [$key,$label])
            <a href="{{ route('club.clients.cards', ['f' => $key]) }}"
               class="ov-tab {{ $f === $key ? 'active' : '' }} {{ $key }}">
                {{ $label }} <span class="ov-n">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </div>

    @if(session('success'))
        <div class="ov-flash">{{ session('success') }}</div>
    @endif

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
                @if($r['client_id'])
                    <a href="{{ route('club.clients.index', ['selected' => $r['client_id']]) }}"
                       class="ov-open" title="Открыть карточку клиента">
                        <i class="bi bi-person-vcard"></i>
                    </a>
                @endif
                @if($r['bucket'] === 'archived')
                    <form method="POST" action="{{ route('club.clients.cards.archive', $r['card_id']) }}">
                        @csrf
                        <input type="hidden" name="f" value="{{ $f }}">
                        <button class="ov-arch restore" title="Вернуть из архива">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                    </form>
                @elseif($r['bucket'] === 'ended')
                    <form method="POST" action="{{ route('club.clients.cards.archive', $r['card_id']) }}">
                        @csrf
                        <input type="hidden" name="f" value="{{ $f }}">
                        <button class="ov-arch" title="В архив">
                            <i class="bi bi-archive"></i>
                        </button>
                    </form>
                @endif
            </div>
        @empty
            <div class="ov-empty">Ничего не найдено</div>
        @endforelse
    </div>
</div>

@include('club.clients._overview_styles')
@endsection
