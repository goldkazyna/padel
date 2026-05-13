@extends('layouts.app')

@section('title', $tournament->name)

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tournament-show.css') }}?v={{ time() }}">
@endpush

@section('content')

{{-- Шапка --}}
@include('club.tournaments.bali_koc.partials._header')

{{-- Информация о турнире --}}
@include('club.tournaments.partials._info')

{{-- Участники --}}
@include('club.tournaments.partials._participants')

{{-- Bali KOC контент --}}
@if($tournament->baliKocPairs->isNotEmpty())
    <div class="card-body-dark">

        @php
            $pairsCount = $tournament->baliKocPairs->count();
            $courtsCount = (int) ($pairsCount / 2);
            $roundsPlayed = $tournament->baliKocRounds->count();
        @endphp
        <div class="alert-info-custom mb-4">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Пар:</strong> {{ $pairsCount }}
            &nbsp;|&nbsp;
            <strong>Кортов:</strong> {{ $courtsCount }}
            &nbsp;|&nbsp;
            <strong>Сыграно раундов:</strong> {{ $roundsPlayed }}
        </div>

        @include('club.tournaments.bali_koc.partials._leaderboard')

        @if($tournament->baliKocRounds->isNotEmpty())
            <div class="section-subheader">
                <i class="bi bi-calendar3"></i> Раунды
            </div>
            @include('club.tournaments.bali_koc.partials._rounds')
        @endif
    </div>
@endif

@endsection
