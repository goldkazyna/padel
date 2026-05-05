@extends('layouts.app')
@section('title', 'Модераторы')
@section('content')

<div class="mod-container">
    <div class="mod-header">
        <h1 class="mod-title">Модераторы — {{ $club->name ?? '' }}</h1>
        <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addModal">+ Добавить модератора</button>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    @forelse($moderators as $mod)
        @php $fullAccess = (bool)($mod->pivot->tournaments_full_access ?? false); @endphp
        <div class="mod-card">
            <div class="mod-card-left">
                <div class="mod-avatar">{{ mb_strtoupper(mb_substr($mod->first_name ?? $mod->name, 0, 1)) }}</div>
                <div class="mod-info">
                    <div class="mod-name">{{ $mod->name }}</div>
                    <div class="mod-email">{{ $mod->email }}</div>
                    <div style="margin-top:4px;display:flex;align-items:center;gap:6px;">
                        @if($fullAccess)
                            <span style="background:rgba(34,197,94,0.15);color:#22c55e;border:1px solid rgba(34,197,94,0.3);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;">Полный доступ</span>
                        @else
                            <span style="background:rgba(113,113,122,0.15);color:#a1a1aa;border:1px solid rgba(113,113,122,0.3);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;">Только модерация</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="mod-card-right">
                <button class="action-btn edit" title="Права на турниры" data-bs-toggle="modal" data-bs-target="#permModal{{ $mod->id }}"><i class="bi bi-shield-check"></i></button>
                <button class="action-btn edit" title="Сменить пароль" data-bs-toggle="modal" data-bs-target="#passModal{{ $mod->id }}"><i class="bi bi-key"></i></button>
                <form action="{{ route('club.moderators.destroy', $mod) }}" method="POST" style="display:inline;" onsubmit="return confirm('Удалить модератора {{ $mod->name }}?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="action-btn delete" title="Удалить"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        </div>
        <!-- Permissions Modal -->
        <div class="modal fade" id="permModal{{ $mod->id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background:#111113;border:1px solid #27272a;border-radius:16px;">
                    <div class="modal-header" style="border-bottom:1px solid #27272a;padding:20px 24px;">
                        <h5 class="modal-title" style="font-weight:700;">Права — {{ $mod->name }}</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="{{ route('club.moderators.updatePermissions', $mod) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="modal-body" style="padding:24px;">
                            <div class="form-group">
                                <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                    <input type="checkbox" name="tournaments_full_access" value="1" {{ $fullAccess ? 'checked' : '' }} style="width:18px;height:18px;margin:0;cursor:pointer;">
                                    Полный доступ к турнирам
                                </label>
                                <small style="color:#888;font-size:11px;">Без галочки — только модерация заявок и ввод счёта. С галочкой — может создавать, редактировать и удалять турниры (как админ клуба).</small>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-top:1px solid #27272a;padding:20px 24px;">
                            <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                            <button type="submit" class="btn-save">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- Password Modal -->
        <div class="modal fade" id="passModal{{ $mod->id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
                    <div class="modal-header" style="border-bottom: 1px solid #27272a; padding: 20px 24px;">
                        <h5 class="modal-title" style="font-weight: 700;">Сменить пароль — {{ $mod->name }}</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="{{ route('club.moderators.updatePassword', $mod) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="modal-body" style="padding: 24px;">
                            <div class="form-group">
                                <label class="form-label">Новый пароль *</label>
                                <input type="password" name="password" class="form-input" placeholder="Минимум 6 символов" minlength="6" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Подтвердите пароль *</label>
                                <input type="password" name="password_confirmation" class="form-input" placeholder="Повторите пароль" minlength="6" required>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-top: 1px solid #27272a; padding: 20px 24px;">
                            <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                            <button type="submit" class="btn-save">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="empty-state">
            <p>Нет модераторов</p>
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addModal">+ Добавить модератора</button>
        </div>
    @endforelse
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="modal-header" style="border-bottom: 1px solid #27272a; padding: 20px 24px;">
                <h5 class="modal-title" style="font-weight: 700;">Добавить модератора</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('club.moderators.store') }}" method="POST">
                @csrf
                <div class="modal-body" style="padding: 24px;">
                    <div class="form-group">
                        <label class="form-label">Имя *</label>
                        <input type="text" name="first_name" class="form-input" placeholder="Имя" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Фамилия *</label>
                        <input type="text" name="last_name" class="form-input" placeholder="Фамилия" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-input" placeholder="email@example.com" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Пароль *</label>
                        <input type="password" name="password" class="form-input" placeholder="Минимум 6 символов" minlength="6" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="tournaments_full_access" value="1" style="width:18px;height:18px;margin:0;cursor:pointer;">
                            Полный доступ к турнирам
                        </label>
                        <small style="color:#888;font-size:11px;">Без галочки — только модерация заявок и ввод счёта (как сейчас). С галочкой — может создавать, редактировать и удалять турниры (как админ).</small>
                    </div>
                    <small style="color: #52525b; font-size: 11px;">Модератор сможет входить по email и паролю и управлять расписанием кортов</small>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #27272a; padding: 20px 24px;">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-save">Добавить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .mod-container { max-width: 800px; margin: 0 auto; padding: 32px 24px; }
    .mod-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
    .mod-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .btn-add { display: flex; align-items: center; gap: 8px; background: #22c55e; color: #0a0a0b; border: none; padding: 12px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-add:hover { background: #16a34a; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .mod-card { display: flex; align-items: center; justify-content: space-between; background: #111113; border: 1px solid #27272a; border-radius: 14px; padding: 16px 20px; margin-bottom: 8px; }
    .mod-card-left { display: flex; align-items: center; gap: 14px; }
    .mod-avatar { width: 42px; height: 42px; border-radius: 12px; background: #22c55e; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; color: #0a0a0b; }
    .mod-name { font-size: 15px; font-weight: 700; }
    .mod-email { font-size: 12px; color: #71717a; }
    .mod-card-right { display: flex; gap: 6px; }
    .action-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 8px; cursor: pointer; color: #71717a; font-size: 16px; transition: all 0.2s; }
    .action-btn.edit:hover { border-color: #3b82f6; color: #3b82f6; }
    .action-btn.delete:hover { border-color: #ef4444; color: #ef4444; }

    .empty-state { text-align: center; padding: 60px 20px; color: #71717a; }
    .empty-state p { font-size: 16px; margin-bottom: 20px; }

    .form-group { margin-bottom: 16px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input::placeholder { color: #52525b; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }
</style>
@endsection
