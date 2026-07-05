@extends('layouts.app')

@section('title', $tournament->name)

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tournament-show.css') }}?v={{ time() }}">
@endpush

@section('content')

{{-- Шапка --}}
@include('club.tournaments.justpadelit.partials._header')

{{-- Информация о турнире --}}
@include('club.tournaments.partials._info')

{{-- Участники --}}
@include('club.tournaments.partials._participants')

{{-- JPI контент --}}
@if($tournament->justPadelItPlayers->count() > 0)
    <div class="card-body-dark">

        @php
            $roundsPlayed = $tournament->justPadelItRounds->count();
            $courtsCount = (int) ($tournament->max_participants / 4);
        @endphp
        <div class="alert-info-custom mb-4">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Сыграно раундов:</strong> {{ $roundsPlayed }}
            &nbsp;|&nbsp;
            <strong>Кортов:</strong> {{ $courtsCount }}
            &nbsp;|&nbsp;
            <strong>Игроков:</strong> {{ $tournament->justPadelItPlayers->count() }}
        </div>

        @include('club.tournaments.justpadelit.partials._leaderboard')

        <div class="section-subheader">
            <i class="bi bi-calendar3"></i> Раунды
        </div>
        @include('club.tournaments.justpadelit.partials._rounds')
    </div>
@endif

@endsection
