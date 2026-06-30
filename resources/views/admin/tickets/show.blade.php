@extends('layouts.app')

@section('content')
<div class="container-fluid py-4" style="max-width: 800px;">
    <div class="mb-3">
        <a href="{{ route('admin.tickets.index') }}" class="text-decoration-none small">&larr; К списку тикетов</a>
    </div>

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h5 mb-1">{{ $ticket->subject }}</h1>
            <div class="text-muted small">{{ $ticket->user->name ?? '—' }}</div>
        </div>
        <div class="d-flex gap-2">
            @php
                $map = ['open' => ['Открыт','warning'], 'answered' => ['Отвечен','info'], 'closed' => ['Закрыт','secondary']];
                [$label, $color] = $map[$ticket->status] ?? [$ticket->status, 'secondary'];
            @endphp
            <span class="badge bg-{{ $color }} align-self-center">{{ $label }}</span>
            @if($ticket->status !== 'closed')
                <form method="POST" action="{{ route('admin.tickets.close', $ticket) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary">Закрыть</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.tickets.reopen', $ticket) }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary">Переоткрыть</button>
                </form>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body d-flex flex-column gap-3">
            @foreach($ticket->messages as $m)
                @php $isSupport = $m->author_type === 'support'; @endphp
                <div class="d-flex {{ $isSupport ? 'justify-content-end' : 'justify-content-start' }}">
                    <div class="p-2 px-3 rounded-3 {{ $isSupport ? 'bg-primary text-white' : 'bg-light' }}" style="max-width: 80%;">
                        <div class="small fw-semibold mb-1">
                            {{ $isSupport ? 'Поддержка' : ($ticket->user->name ?? 'Игрок') }}
                        </div>
                        <div style="white-space: pre-wrap;">{{ $m->body }}</div>
                        @if($m->attachments->count())
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                @foreach($m->attachments as $a)
                                    <a href="{{ $a->url }}" target="_blank">
                                        <img src="{{ $a->url }}" alt="" style="width: 90px; height: 90px; object-fit: cover; border-radius: 8px;">
                                    </a>
                                @endforeach
                            </div>
                        @endif
                        <div class="small {{ $isSupport ? 'text-white-50' : 'text-muted' }} mt-1">
                            {{ optional($m->created_at)->format('d.m.Y H:i') }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" class="card">
        @csrf
        <div class="card-body">
            <label class="form-label small fw-semibold">Ответ</label>
            <textarea name="body" rows="3" class="form-control mb-2" required placeholder="Текст ответа игроку…"></textarea>
            <div class="text-end">
                <button class="btn btn-primary">Отправить ответ</button>
            </div>
        </div>
    </form>
</div>
@endsection
