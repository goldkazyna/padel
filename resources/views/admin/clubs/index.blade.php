@extends('layouts.app')

@section('title', 'Клубы')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Клубы</h2>
        <a href="{{ route('admin.clubs.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Добавить клуб
        </a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Название</th>
                        <th>Адрес</th>
                        <th>Телефон</th>
                        <th>Админов</th>
                        <th>Статус</th>
                        <th width="150">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clubs as $club)
                        <tr>
                            <td>{{ $club->name }}</td>
                            <td>{{ $club->address }}</td>
                            <td>{{ $club->phone ?? '—' }}</td>
                            <td>{{ $club->admins_count }}</td>
                            <td>
                                @if($club->is_active)
                                    <span class="badge bg-success">Активен</span>
                                @else
                                    <span class="badge bg-secondary">Неактивен</span>
                                @endif
                            </td>
                            <td>
								<a href="{{ route('admin.clubs.admins', $club) }}" class="btn btn-sm btn-outline-success" title="Админы">
									<i class="bi bi-people"></i>
								</a>
								<a href="{{ route('admin.clubs.edit', $club) }}" class="btn btn-sm btn-outline-primary" title="Редактировать">
									<i class="bi bi-pencil"></i>
								</a>
                                <form action="{{ route('admin.clubs.destroy', $club) }}" method="POST" class="d-inline" 
                                      onsubmit="return confirm('Удалить клуб?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Клубов пока нет</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection