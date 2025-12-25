@extends('layouts.app')

@section('title', 'Клубы')

@section('content')
<div class="page-header">
    <div>
        <h2>Клубы</h2>
        <p>Управление клубами платформы</p>
    </div>
    <a href="{{ route('admin.clubs.create') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Добавить клуб</span>
    </a>
</div>

<div class="card-dark">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark-custom mb-0">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Адрес</th>
                        <th>Телефон</th>
                        <th>Админов</th>
                        <th>Статус</th>
                        <th width="180">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clubs as $club)
                        <tr>
                            <td class="fw-medium">{{ $club->name }}</td>
                            <td class="text-secondary">{{ $club->address }}</td>
                            <td class="text-secondary">{{ $club->phone ?? '—' }}</td>
                            <td>
                                <span class="badge-secondary-custom">{{ $club->admins_count }}</span>
                            </td>
                            <td>
                                @if($club->is_active)
                                    <span class="badge-success-custom">Активен</span>
                                @else
                                    <span class="badge-secondary-custom">Неактивен</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="{{ route('admin.clubs.admins', $club) }}" class="btn-outline-custom btn-sm" title="Админы">
                                        <i class="bi bi-people"></i>
                                    </a>
                                    <a href="{{ route('admin.clubs.edit', $club) }}" class="btn-outline-custom btn-sm" title="Редактировать">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form action="{{ route('admin.clubs.destroy', $club) }}" method="POST" class="d-inline" 
                                          onsubmit="return confirm('Удалить клуб?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-danger-custom btn-sm" title="Удалить">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-5">
                                <i class="bi bi-buildings fs-1 d-block mb-3 opacity-50"></i>
                                Клубов пока нет
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection