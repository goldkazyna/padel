@extends('layouts.app')

@section('title', 'Админы клуба')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>Админы клуба: {{ $club->name }}</h2>
            <a href="{{ route('admin.clubs.index') }}" class="text-muted">← Назад к списку клубов</a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Текущие админы</h5>
                </div>
                <div class="card-body">
                    @if($club->admins->count() > 0)
                        <ul class="list-group">
                            @foreach($club->admins as $admin)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>{{ $admin->full_name }}</strong>
                                        <br><small class="text-muted">{{ $admin->email }}</small>
                                    </div>
                                    <form action="{{ route('admin.clubs.admins.remove', [$club, $admin]) }}" method="POST"
                                          onsubmit="return confirm('Удалить админа?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted mb-0">Нет назначенных админов</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Добавить админа</h5>
                </div>
                <div class="card-body">
                    @if($players->count() > 0)
                        <form action="{{ route('admin.clubs.admins.add', $club) }}" method="POST">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label">Выберите игрока</label>
                                <select name="user_id" class="form-select" required>
                                    <option value="">-- Выберите --</option>
                                    @foreach($players as $player)
                                        <option value="{{ $player->id }}">
                                            {{ $player->full_name }} ({{ $player->email }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-1"></i> Назначить админом
                            </button>
                        </form>
                    @else
                        <p class="text-muted mb-0">Нет доступных игроков для назначения</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection