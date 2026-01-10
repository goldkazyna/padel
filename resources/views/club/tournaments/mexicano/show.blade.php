@extends('layouts.app')

@section('title', $tournament->name)

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tournament-show.css') }}?v={{ time() }}">
@endpush

@section('content')

{{-- Шапка --}}
@include('club.tournaments.mexicano.partials._header')

{{-- Информация о турнире --}}
@include('club.tournaments.partials._info')

{{-- Участники --}}
@include('club.tournaments.partials._participants')

{{-- Мексикано контент --}}
@if($tournament->mexicanoPlayers->count() > 0)


    <div class="card-body-dark">
        
        {{-- Информация о раундах --}}
        <div class="alert-info-custom mb-4">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Раундов:</strong> {{ $tournament->mexicanoRounds->count() }} / {{ $tournament->rounds_count }}

        </div>

        {{-- Таблица лидеров --}}
        @include('club.tournaments.mexicano.partials._leaderboard')

        {{-- Раунды --}}
        <div class="section-subheader">
            <i class="bi bi-calendar3"></i> Раунды
        </div>
        @include('club.tournaments.mexicano.partials._rounds')
    </div>

@endif

@endsection