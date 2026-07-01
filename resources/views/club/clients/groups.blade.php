@extends('layouts.app')
@section('title', 'Групповые занятия')

@section('content')
<div class="ov-wrap">
    <div class="ov-head">
        <a href="{{ route('club.clients.index') }}" class="ov-back"><i class="bi bi-arrow-left"></i></a>
        <h1>Групповые занятия</h1>
    </div>

    <div class="ov-tabs">
        @foreach([['all','Все'],['ending','Заканчиваются'],['ended','Закончились']] as [$key,$label])
            <a href="{{ route('club.clients.groups', ['f' => $key]) }}"
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
                    <div class="ov-sub"><i class="bi bi-people"></i> {{ $r['group_name'] }}</div>
                </div>
                <div class="ov-meta">
                    @php
                        $rem = $r['remaining'];
                        $cls = $rem <= 0 ? 'red' : ($rem <= 2 ? 'amber' : 'green');
                    @endphp
                    <span class="ov-rem {{ $cls }}">осталось {{ max($rem, 0) }}</span>
                </div>
            </div>
        @empty
            <div class="ov-empty">Ничего не найдено</div>
        @endforelse
    </div>
</div>

@include('club.clients._overview_styles')
@endsection
