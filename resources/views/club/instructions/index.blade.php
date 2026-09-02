{{-- Должностные инструкции клуба: разделы и инструкции внутри них. --}}
@extends('layouts.app')

@section('title', 'Должностные инструкции')

@section('content')
<div class="ins-page">

    <div class="page-header">
        <div>
            <h2>Должностные инструкции</h2>
            <p>
                {{ $club->name }} ·
                @if($total > 0)
                    {{ $total }} {{ trans_choice('инструкция|инструкции|инструкций', $total) }}
                    в {{ $sections->count() }} {{ trans_choice('разделе|разделах|разделах', $sections->count()) }}
                @else
                    что и как делать на каждом этапе работы
                @endif
            </p>
        </div>
        @if($canEdit)
            <div class="ins-head-actions">
                <button type="button" class="btn-outline-custom" onclick="insOpenSection()">
                    <i class="bi bi-folder-plus"></i> Раздел
                </button>
                @if($sections->isNotEmpty())
                    <a href="{{ route('club.instructions.create') }}" class="btn-primary-custom">
                        <i class="bi bi-plus-lg"></i> Инструкция
                    </a>
                @endif
            </div>
        @endif
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="flash-message flash-error">@foreach($errors->all() as $e){{ $e }}<br>@endforeach</div>@endif

    @if($sections->isEmpty())
        <div class="card-dark">
            <div class="card-body ins-empty">
                <i class="bi bi-journal-text"></i>
                <p>Инструкций пока нет</p>
                <span>
                    Начните с раздела — например «Открытие смены», «Брони», «Турниры».
                    Внутри раздела пишутся сами инструкции: что делать по шагам.
                </span>
                @if($canEdit)
                    <button type="button" class="btn-primary-custom" onclick="insOpenSection()">
                        <i class="bi bi-folder-plus"></i> Создать раздел
                    </button>
                @endif
            </div>
        </div>
    @else
        @foreach($sections as $section)
            <div class="card-dark ins-section">
                <div class="card-header">
                    <h5><i class="bi bi-folder2-open"></i> {{ $section->title }}</h5>
                    <span class="ins-count">{{ $section->instructions->count() }}</span>

                    @if($canEdit)
                        <div class="ins-section-actions">
                            <a href="{{ route('club.instructions.create', ['section_id' => $section->id]) }}"
                               class="btn-outline-custom btn-sm" title="Добавить инструкцию в раздел">
                                <i class="bi bi-plus-lg"></i>
                            </a>
                            <button type="button" class="btn-outline-custom btn-sm" title="Переименовать раздел"
                                    onclick="insOpenSection({{ $section->id }}, @js($section->title))">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('club.instructions.sections.destroy', $section) }}"
                                  onsubmit="return confirm('Удалить раздел «{{ $section->title }}» вместе со всеми инструкциями внутри?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-danger-custom btn-sm" title="Удалить раздел">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    @endif
                </div>

                <div class="card-body ins-body">
                    @if($section->instructions->isEmpty())
                        <div class="ins-section-empty">
                            В разделе пока нет инструкций.
                            @if($canEdit)
                                <a href="{{ route('club.instructions.create', ['section_id' => $section->id]) }}">Добавить первую</a>
                            @endif
                        </div>
                    @else
                        @foreach($section->instructions as $instruction)
                            <div class="ins-row">
                                <a href="{{ route('club.instructions.show', $instruction) }}" class="ins-row-main">
                                    <span class="ins-row-title">{{ $instruction->title }}</span>
                                    @if($instruction->excerpt() !== '')
                                        <span class="ins-row-excerpt">{{ $instruction->excerpt() }}</span>
                                    @endif
                                </a>

                                @if($instruction->files_count ?? $instruction->files()->count())
                                    <span class="ins-files" title="Файлы во вложении">
                                        <i class="bi bi-paperclip"></i>{{ $instruction->files()->count() }}
                                    </span>
                                @endif

                                @if($canEdit)
                                    <div class="ins-row-actions">
                                        <form method="POST" action="{{ route('club.instructions.move', $instruction) }}">
                                            @csrf
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="ins-icon" title="Выше"><i class="bi bi-chevron-up"></i></button>
                                        </form>
                                        <form method="POST" action="{{ route('club.instructions.move', $instruction) }}">
                                            @csrf
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="ins-icon" title="Ниже"><i class="bi bi-chevron-down"></i></button>
                                        </form>
                                        <a href="{{ route('club.instructions.edit', $instruction) }}" class="ins-icon" title="Править">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        @endforeach
    @endif

    @if($canEdit)
        {{-- Раздел заводим в модалке: отдельная страница ради одного поля — перебор. --}}
        <div class="ins-modal" id="insSectionModal" hidden>
            <div class="ins-modal-card">
                <h3 id="insSectionTitle">Новый раздел</h3>
                <form method="POST" id="insSectionForm" action="{{ route('club.instructions.sections.store') }}">
                    @csrf
                    <input type="hidden" name="_method" id="insSectionMethod" value="POST">
                    <label class="form-label">Название раздела</label>
                    <input type="text" name="title" id="insSectionInput" class="form-control"
                           maxlength="120" required placeholder="Например: Открытие смены">
                    <div class="ins-modal-actions">
                        <button type="button" class="btn-outline-custom" onclick="insCloseSection()">Отмена</button>
                        <button type="submit" class="btn-primary-custom">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

<style>
.ins-page{max-width:1200px;}
.ins-head-actions{display:flex;gap:10px;}

.ins-section{margin-bottom:16px;}
.ins-section .card-header{display:flex;align-items:center;gap:12px;}
.ins-count{font-size:12px;font-weight:700;color:var(--text-secondary);
  background:var(--bg-secondary);border-radius:20px;padding:2px 10px;}
.ins-section-actions{margin-left:auto;display:flex;gap:8px;align-items:center;}
.ins-section-actions form{margin:0;}
.ins-body{padding:8px 12px;}

.ins-row{display:flex;align-items:center;gap:12px;padding:12px;border-radius:10px;}
.ins-row:hover{background:var(--bg-card-hover);}
.ins-row + .ins-row{border-top:1px solid var(--border);}
.ins-row-main{flex:1;min-width:0;display:block;text-decoration:none;}
.ins-row-title{display:block;color:var(--text-primary);font-size:15px;font-weight:600;}
.ins-row-excerpt{display:block;color:var(--text-secondary);font-size:13px;margin-top:3px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ins-files{display:inline-flex;align-items:center;gap:4px;color:var(--text-muted);font-size:12.5px;}
.ins-row-actions{display:flex;gap:4px;align-items:center;}
.ins-row-actions form{margin:0;}
.ins-icon{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);
  background:transparent;color:var(--text-secondary);display:inline-flex;
  align-items:center;justify-content:center;cursor:pointer;text-decoration:none;}
.ins-icon:hover{color:var(--text-primary);border-color:var(--border-light);}

.ins-section-empty{color:var(--text-secondary);font-size:13.5px;padding:10px 12px;}
.ins-section-empty a{color:var(--accent);}

.ins-empty{text-align:center;padding:40px 20px;}
.ins-empty i{font-size:32px;color:var(--text-muted);display:block;margin-bottom:14px;}
.ins-empty p{margin:0 0 6px;font-size:16px;font-weight:600;color:var(--text-primary);}
.ins-empty span{display:block;max-width:520px;margin:0 auto 18px;
  color:var(--text-secondary);font-size:13.5px;line-height:1.5;}

.ins-modal{position:fixed;inset:0;z-index:1200;display:flex;align-items:center;
  justify-content:center;background:rgba(0,0,0,.6);padding:20px;}
.ins-modal[hidden]{display:none;}
.ins-modal-card{width:100%;max-width:440px;background:var(--bg-card);
  border:1px solid var(--border);border-radius:16px;padding:24px;}
.ins-modal-card h3{margin:0 0 16px;font-size:18px;font-weight:700;}
.ins-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;}
</style>

<script>
// Одна модалка на «создать» и «переименовать»: форма та же, меняется адрес.
function insOpenSection(id, title) {
    const modal = document.getElementById('insSectionModal');
    const form = document.getElementById('insSectionForm');
    const input = document.getElementById('insSectionInput');

    if (id) {
        document.getElementById('insSectionTitle').textContent = 'Переименовать раздел';
        form.action = '{{ url('club/instructions/sections') }}/' + id;
        document.getElementById('insSectionMethod').value = 'PUT';
        input.value = title || '';
    } else {
        document.getElementById('insSectionTitle').textContent = 'Новый раздел';
        form.action = '{{ route('club.instructions.sections.store') }}';
        document.getElementById('insSectionMethod').value = 'POST';
        input.value = '';
    }

    modal.hidden = false;
    input.focus();
}

function insCloseSection() {
    document.getElementById('insSectionModal').hidden = true;
}

document.getElementById('insSectionModal')?.addEventListener('click', function (e) {
    if (e.target === this) insCloseSection();
});
</script>
@endsection
