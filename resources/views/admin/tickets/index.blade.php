@extends('layouts.app')

@section('content')
<div class="container-fluid py-4" style="max-width: 1000px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Тикеты поддержки</h1>
        <div class="btn-group btn-group-sm">
            <a href="{{ route('admin.tickets.index') }}"
               class="btn btn-outline-secondary {{ !$status ? 'active' : '' }}">Все</a>
            <a href="{{ route('admin.tickets.index', ['status' => 'open']) }}"
               class="btn btn-outline-secondary {{ $status === 'open' ? 'active' : '' }}">Открытые</a>
            <a href="{{ route('admin.tickets.index', ['status' => 'answered']) }}"
               class="btn btn-outline-secondary {{ $status === 'answered' ? 'active' : '' }}">Отвеченные</a>
            <a href="{{ route('admin.tickets.index', ['status' => 'closed']) }}"
               class="btn btn-outline-secondary {{ $status === 'closed' ? 'active' : '' }}">Закрытые</a>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Тема</th>
                        <th>Игрок</th>
                        <th>Статус</th>
                        <th>Обновлён</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        <tr>
                            <td>
                                <a href="{{ route('admin.tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">
                                    {{ $ticket->subject }}
                                </a>
                                @if($ticket->player_unread_count > 0)
                                    <span class="badge bg-danger ms-1">{{ $ticket->player_unread_count }}</span>
                                @endif
                            </td>
                            <td>{{ $ticket->user->name ?? '—' }}</td>
                            <td>
                                @php
                                    $map = ['open' => ['Открыт','warning'], 'answered' => ['Отвечен','info'], 'closed' => ['Закрыт','secondary']];
                                    [$label, $color] = $map[$ticket->status] ?? [$ticket->status, 'secondary'];
                                @endphp
                                <span class="badge bg-{{ $color }}">{{ $label }}</span>
                            </td>
                            <td class="text-muted small">{{ optional($ticket->last_message_at)->format('d.m.Y H:i') }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.tickets.show', $ticket) }}" class="btn btn-sm btn-outline-primary">Открыть</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Тикетов нет</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $tickets->links() }}</div>
</div>
@endsection
