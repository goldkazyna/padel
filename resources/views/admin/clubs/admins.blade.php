@extends('layouts.app')

@section('title', 'Админы клуба')

@section('content')
<div class="page-header">
    <div>
        <h2>Админы клуба</h2>
        <p>{{ $club->name }}</p>
    </div>
    <a href="{{ route('admin.clubs.index') }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i>
        <span>Назад к клубам</span>
    </a>
</div>

<div class="row g-4">
    <!-- Current admins -->
    <div class="col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-people"></i> Текущие админы</h5>
            </div>
            <div class="card-body">
                @if($club->admins->count() > 0)
                    @foreach($club->admins as $admin)
                        <div class="d-flex align-items-center justify-content-between p-3 rounded-3 mb-3" 
                             style="background: var(--bg-secondary);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-avatar">
                                    {{ strtoupper(substr($admin->first_name, 0, 1) . substr($admin->last_name, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="fw-medium">{{ $admin->full_name }}</div>
                                    <small class="text-secondary">{{ $admin->email }}</small>
                                </div>
                            </div>
                            <form action="{{ route('admin.clubs.admins.remove', [$club, $admin]) }}" method="POST"
                                  onsubmit="return confirm('Удалить админа?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger-custom btn-sm">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </div>
                    @endforeach
                @else
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-people fs-1 d-block mb-3 opacity-50"></i>
                        Нет назначенных админов
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Add admin -->
    <div class="col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-person-plus"></i> Добавить админа</h5>
            </div>
            <div class="card-body">
                @if($players->count() > 0)
                    <form action="{{ route('admin.clubs.admins.add', $club) }}" method="POST">
                        @csrf
                        <div class="mb-4">
                            <label class="form-label">Выберите игрока</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">— Выберите игрока —</option>
                                @foreach($players as $player)
                                    <option value="{{ $player->id }}">
                                        {{ $player->full_name }} ({{ $player->email }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-plus-circle"></i> Назначить админом
                        </button>
                    </form>
                @else
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-person-x fs-1 d-block mb-3 opacity-50"></i>
                        Нет доступных игроков
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection