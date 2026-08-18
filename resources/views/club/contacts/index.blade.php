@extends('layouts.app')

@section('title', 'Контакты')

@section('content')
<div class="page-header">
    <div>
        <h2>Контакты</h2>
        <p>Персонал, поставщики и все, кого потом придётся искать</p>
    </div>
    <button type="button" class="btn-primary-custom" onclick="openContact()">
        <i class="bi bi-person-plus"></i>
        <span>Добавить контакт</span>
    </button>
</div>

<form method="GET" class="ct-search">
    <input type="text" name="q" value="{{ $search }}" class="form-control"
           placeholder="Поиск по имени, должности, телефону, почте или заметке">
    @if($groupId !== null)<input type="hidden" name="group" value="{{ $groupId }}">@endif
</form>

{{-- Группы --}}
<div class="ct-groups">
    <a href="{{ route('club.contacts.index', ['q' => $search]) }}"
       class="ct-group {{ $groupId === null ? 'is-active' : '' }}">Все</a>

    @foreach($groups as $group)
        <span class="ct-group-wrap">
            <a href="{{ route('club.contacts.index', ['group' => $group->id, 'q' => $search]) }}"
               class="ct-group {{ (string) $groupId === (string) $group->id ? 'is-active' : '' }}">
                {{ $group->name }}
                <b>{{ $group->contacts_count }}</b>
            </a>
            <button type="button" class="ct-group-edit" title="Переименовать"
                    onclick="renameGroup({{ $group->id }}, @js($group->name))">
                <i class="bi bi-pencil"></i>
            </button>
        </span>
    @endforeach

    @if($withoutGroup > 0)
        <a href="{{ route('club.contacts.index', ['group' => 'none', 'q' => $search]) }}"
           class="ct-group {{ $groupId === 'none' ? 'is-active' : '' }}">
            Без группы <b>{{ $withoutGroup }}</b>
        </a>
    @endif

    <button type="button" class="ct-group ct-group-add" onclick="openGroup()">
        <i class="bi bi-plus-lg"></i> Группа
    </button>
</div>

{{-- Контакты --}}
<div class="ct-list">
    @forelse($contacts as $contact)
        <div class="ct-card">
            <div class="ct-card-head">
                <div class="ct-avatar">{{ mb_strtoupper(mb_substr($contact->name, 0, 1)) }}</div>
                <div class="ct-card-title">
                    <div class="ct-name">{{ $contact->name }}</div>
                    @if($contact->position)<div class="ct-position">{{ $contact->position }}</div>@endif
                </div>
                @if($contact->group)
                    <span class="ct-tag">{{ $contact->group->name }}</span>
                @endif
            </div>

            @if($contact->phone || $contact->email)
                <div class="ct-lines">
                    @if($contact->phone)
                        <a class="ct-line" href="tel:{{ preg_replace('/[^\d+]/', '', $contact->phone) }}">
                            <i class="bi bi-telephone"></i> {{ $contact->phone }}
                        </a>
                    @endif
                    @if($contact->email)
                        <a class="ct-line" href="mailto:{{ $contact->email }}">
                            <i class="bi bi-envelope"></i> {{ $contact->email }}
                        </a>
                    @endif
                </div>
            @endif

            @if($contact->note)
                <div class="ct-note">{{ $contact->note }}</div>
            @endif

            <div class="ct-card-foot">
                <button type="button" class="btn-outline-custom btn-sm"
                        onclick='openContact(@json($contact))'>
                    <i class="bi bi-pencil"></i>
                </button>
                <form action="{{ route('club.contacts.destroy', $contact) }}" method="POST" class="d-inline"
                      onsubmit="return confirm('Удалить контакт «{{ $contact->name }}»?')">
                    @csrf
                    @method('DELETE')
                    <button class="btn-danger-custom btn-sm" title="Удалить"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        </div>
    @empty
        <div class="ct-empty">
            <i class="bi bi-person-lines-fill"></i>
            <p>{{ $search !== '' ? 'Ничего не нашлось' : 'Контактов пока нет' }}</p>
        </div>
    @endforelse
</div>

{{-- Окно контакта --}}
<div class="ct-modal" id="contactModal">
    <form class="ct-modal-box" id="contactForm" method="POST" action="{{ route('club.contacts.store') }}">
        @csrf
        <input type="hidden" name="_method" id="contactMethod" value="POST">
        <div class="ct-modal-head">
            <span id="contactModalTitle">Новый контакт</span>
            <button type="button" class="ct-modal-close" onclick="closeContact()">&times;</button>
        </div>

        <label class="form-label">Имя *</label>
        <input type="text" name="name" id="contactName" class="form-control mb-3" required>

        <label class="form-label">Кто это</label>
        <input type="text" name="position" id="contactPosition" class="form-control mb-3"
               placeholder="Например: электрик, поставщик мячей">

        <div class="ct-modal-row">
            <div>
                <label class="form-label">Телефон</label>
                <input type="text" name="phone" id="contactPhone" class="form-control mb-3">
            </div>
            <div>
                <label class="form-label">Почта</label>
                <input type="email" name="email" id="contactEmail" class="form-control mb-3">
            </div>
        </div>

        <label class="form-label">Группа</label>
        <select name="contact_group_id" id="contactGroup" class="form-control mb-3">
            <option value="">Без группы</option>
            @foreach($groups as $group)
                <option value="{{ $group->id }}">{{ $group->name }}</option>
            @endforeach
        </select>

        <label class="form-label">Заметка</label>
        <textarea name="note" id="contactNote" class="form-control mb-3" rows="3"
                  placeholder="Что важно помнить: график, условия, чем занимается"></textarea>

        <button type="submit" class="btn-primary-custom w-100">Сохранить</button>
    </form>
</div>

{{-- Окно группы --}}
<div class="ct-modal" id="groupModal">
    <form class="ct-modal-box ct-modal-narrow" id="groupForm" method="POST" action="{{ route('club.contactGroups.store') }}">
        @csrf
        <input type="hidden" name="_method" id="groupMethod" value="POST">
        <div class="ct-modal-head">
            <span id="groupModalTitle">Новая группа</span>
            <button type="button" class="ct-modal-close" onclick="closeGroup()">&times;</button>
        </div>

        <label class="form-label">Название</label>
        <input type="text" name="name" id="groupName" class="form-control mb-3"
               placeholder="Персонал, поставщики, аренда…" required>

        <button type="submit" class="btn-primary-custom w-100">Сохранить</button>

        <button type="button" class="ct-group-delete" id="groupDelete" onclick="deleteGroup()" hidden>
            Удалить группу
        </button>
    </form>
</div>

<form id="groupDeleteForm" method="POST" class="d-none">
    @csrf
    @method('DELETE')
</form>

<script>
var groupsBase = @json(route('club.contactGroups.store'));
var contactsBase = @json(route('club.contacts.store'));
var editingGroupId = null;

function openContact(contact) {
    var form = document.getElementById('contactForm');
    document.getElementById('contactModalTitle').textContent = contact ? 'Контакт' : 'Новый контакт';
    document.getElementById('contactMethod').value = contact ? 'PUT' : 'POST';
    form.action = contact ? contactsBase + '/' + contact.id : contactsBase;

    document.getElementById('contactName').value = contact ? contact.name : '';
    document.getElementById('contactPosition').value = (contact && contact.position) || '';
    document.getElementById('contactPhone').value = (contact && contact.phone) || '';
    document.getElementById('contactEmail').value = (contact && contact.email) || '';
    document.getElementById('contactNote').value = (contact && contact.note) || '';
    document.getElementById('contactGroup').value = (contact && contact.contact_group_id) || '';

    document.getElementById('contactModal').classList.add('is-open');
}
function closeContact() { document.getElementById('contactModal').classList.remove('is-open'); }

function openGroup() {
    editingGroupId = null;
    document.getElementById('groupModalTitle').textContent = 'Новая группа';
    document.getElementById('groupMethod').value = 'POST';
    document.getElementById('groupForm').action = groupsBase;
    document.getElementById('groupName').value = '';
    document.getElementById('groupDelete').hidden = true;
    document.getElementById('groupModal').classList.add('is-open');
}

function renameGroup(id, name) {
    editingGroupId = id;
    document.getElementById('groupModalTitle').textContent = 'Группа';
    document.getElementById('groupMethod').value = 'PUT';
    document.getElementById('groupForm').action = groupsBase + '/' + id;
    document.getElementById('groupName').value = name;
    document.getElementById('groupDelete').hidden = false;
    document.getElementById('groupModal').classList.add('is-open');
}
function closeGroup() { document.getElementById('groupModal').classList.remove('is-open'); }

function deleteGroup() {
    if (!editingGroupId) return;
    // Контакты остаются: удаляется только группа.
    if (!confirm('Удалить группу? Контакты останутся, но без группы.')) return;
    var form = document.getElementById('groupDeleteForm');
    form.action = groupsBase + '/' + editingGroupId;
    form.submit();
}

// Клик по подложке и Escape закрывают окно.
document.querySelectorAll('.ct-modal').forEach(function (modal) {
    modal.addEventListener('click', function (e) {
        if (e.target === modal) modal.classList.remove('is-open');
    });
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.ct-modal.is-open').forEach(function (m) { m.classList.remove('is-open'); });
    }
});
</script>

<style>
.ct-search { max-width: 520px; margin-bottom: 16px; }

.ct-groups { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; align-items: center; }
.ct-group-wrap { display: inline-flex; align-items: center; }
.ct-group {
    display: inline-flex; align-items: center; gap: 7px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 99px; padding: 7px 14px;
    color: var(--text-secondary); font-size: 13.5px; font-weight: 600;
    text-decoration: none; cursor: pointer;
}
.ct-group:hover { border-color: var(--border-light); color: var(--text-primary); }
.ct-group b { color: var(--text-muted); font-size: 12px; font-weight: 700; }
.ct-group.is-active { background: var(--accent-glow); border-color: transparent; color: var(--accent); }
.ct-group.is-active b { color: var(--accent); }
.ct-group-edit {
    background: none; border: none; color: var(--text-muted);
    padding: 4px 6px; cursor: pointer; font-size: 12px;
}
.ct-group-edit:hover { color: var(--accent); }
.ct-group-add { border-style: dashed; }

.ct-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
.ct-card {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 16px;
    display: flex; flex-direction: column;
}
.ct-card-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
.ct-avatar {
    width: 40px; height: 40px; flex: none; border-radius: 12px;
    display: grid; place-items: center;
    background: var(--accent-glow); color: var(--accent);
    font-weight: 800; font-size: 16px;
}
.ct-card-title { min-width: 0; }
.ct-name { font-size: 15px; font-weight: 700; color: var(--text-primary); word-break: break-word; }
.ct-position { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
.ct-tag {
    margin-left: auto; flex: none;
    background: rgba(255,255,255,.06); color: var(--text-secondary);
    font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 99px;
}
.ct-lines { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
.ct-line {
    display: inline-flex; align-items: center; gap: 8px;
    color: var(--text-secondary); font-size: 13.5px; text-decoration: none;
}
.ct-line i { color: var(--text-muted); }
.ct-line:hover { color: var(--accent); }
.ct-note {
    background: var(--bg-secondary); border-radius: 10px; padding: 10px 12px;
    color: var(--text-secondary); font-size: 13px; line-height: 1.5;
    white-space: pre-wrap; word-break: break-word; margin-bottom: 12px;
}
.ct-card-foot { display: flex; gap: 6px; margin-top: auto; }

.ct-empty {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 46px 20px; text-align: center; color: var(--text-muted);
    grid-column: 1 / -1;
}
.ct-empty i { font-size: 30px; display: block; margin-bottom: 10px; }
.ct-empty p { margin: 0; font-size: 14px; }

.ct-modal {
    position: fixed; inset: 0; z-index: 1080; display: none;
    align-items: center; justify-content: center; padding: 20px; background: rgba(0,0,0,.66);
}
.ct-modal.is-open { display: flex; }
.ct-modal-box {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 16px; padding: 22px; width: 100%; max-width: 520px;
    max-height: 88vh; overflow: auto;
}
.ct-modal-narrow { max-width: 380px; }
.ct-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 17px; font-weight: 800; color: var(--text-primary); margin-bottom: 18px;
}
.ct-modal-close { background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer; line-height: 1; }
.ct-modal-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 576px) { .ct-modal-row { grid-template-columns: 1fr; } }
.ct-group-delete {
    background: none; border: none; color: var(--danger, #ef4444);
    font-size: 13px; margin-top: 14px; cursor: pointer; width: 100%;
}
</style>
@endsection
