@extends('layouts.app')

@section('title', 'Рейтинг игроков')

@section('content')
<div class="page-header">
    <div>
        <h2>Рейтинг игроков</h2>
        <p>Топ игроков платформы</p>
    </div>
</div>

<div class="card-dark">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark-custom mb-0">
                <thead>
                    <tr>
                        <th width="80">#</th>
                        <th>Игрок</th>
                        <th>Рейтинг</th>
                        <th>Уровень</th>
                        <th>Матчей</th>
                        <th>Побед</th>
                        <th>Винрейт</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($players as $index => $player)
                        <tr class="{{ $player->id === auth()->id() ? 'table-active' : '' }}">
                            <td>
                                @if($index < 3)
                                    <span class="badge-{{ $index === 0 ? 'warning' : ($index === 1 ? 'secondary' : 'info') }}-custom">
                                        {{ $index + 1 }}
                                    </span>
                                @else
                                    <span class="text-secondary">{{ $index + 1 }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="user-avatar" style="width:36px;height:36px;font-size:0.85rem;">
                                        {{ strtoupper(substr($player->first_name, 0, 1) . substr($player->last_name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <div class="fw-medium">{{ $player->full_name }}</div>
                                        @if($player->id === auth()->id())
                                            <small class="text-success">Это вы</small>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="fw-bold">{{ $player->rating }}</td>
                            <td>
                                <span class="badge-success-custom">{{ $player->level }}</span>
                            </td>
                            <td>{{ $player->wins() + $player->losses() }}</td>
                            <td class="text-success">{{ $player->wins() }}</td>
                            <td>{{ $player->winRate() }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-secondary py-5">
                                Пока нет игроков
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection