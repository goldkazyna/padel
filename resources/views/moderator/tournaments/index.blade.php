@extends('layouts.app')
@section('title', 'Турниры на модерации')
@section('content')

<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-trophy me-2"></i>Турниры</h1>
        <p class="text-muted">Открытые турниры {{ $club->name }}</p>
    </div>

    @if($tournaments->isEmpty())
        <div class="card-dark">
            <div class="card-body-dark text-center py-5">
                <i class="bi bi-calendar-x display-4 text-muted"></i>
                <p class="mt-3 text-muted">Нет открытых турниров</p>
            </div>
        </div>
    @else
        <div class="row g-4">
            @foreach($tournaments as $tournament)
                @php
                    $pendingCount = $tournament->participants()->wherePivot('status', 'pending')->count();
                    $registeredCount = $tournament->participants()->wherePivot('status', 'registered')->count();
                @endphp
                <div class="col-md-6 col-lg-4">
                    <div class="card-dark h-100">
                        <div class="card-body-dark">
                            <h5 class="mb-2">{{ $tournament->name }}</h5>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-calendar me-1"></i>{{ $tournament->start_date->format('d.m.Y H:i') }}
                            </p>
                            
                            <div class="d-flex gap-3 mb-3">
                                <span class="badge bg-success">{{ $registeredCount }}/{{ $tournament->max_participants }}</span>
                                @if($pendingCount > 0)
                                    <span class="badge bg-warning">{{ $pendingCount }} на модерации</span>
                                @endif
                            </div>

                            <a href="{{ route('moderator.tournaments.show', $tournament) }}" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-eye me-1"></i>Открыть
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@endsection