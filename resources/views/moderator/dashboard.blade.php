@extends('layouts.app')
@section('title', 'Панель модератора')
@section('content')

<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-shield-check me-2"></i>Панель модератора</h1>
        <p class="text-muted">{{ $club->name }}</p>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card-dark">
                <div class="card-body-dark text-center py-4">
                    <div class="display-4 text-success mb-2">{{ $openTournaments }}</div>
                    <div class="text-muted">Открытых турниров</div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-dark">
                <div class="card-body-dark text-center py-4">
                    <div class="display-4 text-warning mb-2">{{ $pendingParticipants }}</div>
                    <div class="text-muted">Заявок на модерации</div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <a href="{{ route('moderator.tournaments.index') }}" class="btn btn-success btn-lg">
            <i class="bi bi-trophy me-2"></i>Перейти к турнирам
        </a>
    </div>
</div>

@endsection