{{-- Создание и правка должностной инструкции. --}}
@extends('layouts.app')

@section('title', $instruction->exists ? 'Правка инструкции' : 'Новая инструкция')

@section('content')
<div class="ins-form-page">

    <div class="page-header">
        <div>
            <h2>{{ $instruction->exists ? 'Правка инструкции' : 'Новая инструкция' }}</h2>
            <p>{{ $club->name }} · что и как делать по шагам</p>
        </div>
        <div class="ins-head-actions">
            <a href="{{ $instruction->exists ? route('club.instructions.show', $instruction) : route('club.instructions.index') }}"
               class="btn-outline-custom">
                <i class="bi bi-arrow-left"></i> Назад
            </a>
            @if($instruction->exists)
                <form method="POST" action="{{ route('club.instructions.destroy', $instruction) }}"
                      onsubmit="return confirm('Удалить инструкцию «{{ $instruction->title }}»?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn-danger-custom"><i class="bi bi-trash"></i> Удалить</button>
                </form>
            @endif
        </div>
    </div>

    @if($errors->any())<div class="flash-message flash-error">@foreach($errors->all() as $e){{ $e }}<br>@endforeach</div>@endif

    <form method="POST" enctype="multipart/form-data"
          action="{{ $instruction->exists ? route('club.instructions.update', $instruction) : route('club.instructions.store') }}">
        @csrf
        @if($instruction->exists) @method('PUT') @endif

        <div class="card-dark">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Название *</label>
                        <input type="text" name="title" class="form-control" maxlength="200" required
                               value="{{ old('title', $instruction->title) }}"
                               placeholder="Например: Приём брони по телефону">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Раздел *</label>
                        <select name="section_id" class="form-select" required>
                            @foreach($sections as $section)
                                <option value="{{ $section->id }}"
                                    {{ (int) old('section_id', $instruction->section_id) === $section->id ? 'selected' : '' }}>
                                    {{ $section->title }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <label class="form-label">Текст инструкции</label>
                <div class="ins-editor-toolbar">
                    <button type="button" data-cmd="formatBlock" data-value="h3" title="Заголовок"><b>H</b></button>
                    <button type="button" data-cmd="bold" title="Жирный"><b>Ж</b></button>
                    <button type="button" data-cmd="italic" title="Курсив"><i>К</i></button>
                    <button type="button" data-cmd="underline" title="Подчёркнутый"><u>П</u></button>
                    <span class="ins-toolbar-sep"></span>
                    <button type="button" data-cmd="insertOrderedList" title="Нумерованный список">1.</button>
                    <button type="button" data-cmd="insertUnorderedList" title="Список">•</button>
                    <span class="ins-toolbar-sep"></span>
                    <button type="button" data-cmd="removeFormat" title="Убрать оформление">✕</button>
                </div>
                <div class="ins-editor" id="insEditor" contenteditable="true">{!! old('body', $instruction->body) !!}</div>
                <input type="hidden" name="body" id="insBody">
                <small class="text-secondary">
                    Пишите шагами: короткие пункты читаются в спешке, сплошной текст — нет.
                </small>

                <div class="ins-upload">
                    <label class="form-label">Файлы: скриншоты и PDF</label>
                    <input type="file" name="files[]" class="form-control" multiple
                           accept="image/jpeg,image/png,image/webp,image/gif,application/pdf">
                    <small class="text-secondary">Можно выбрать несколько. Уже загруженные останутся на месте.</small>
                </div>

                @if($instruction->exists && $instruction->files->isNotEmpty())
                    <div class="ins-existing">
                        @foreach($instruction->files as $file)
                            <span class="ins-chip">
                                <i class="bi {{ $file->is_image ? 'bi-image' : 'bi-file-earmark-pdf' }}"></i>
                                {{ $file->name }} · {{ $file->humanSize() }}
                            </span>
                        @endforeach
                        <small class="text-secondary d-block mt-2">Удалить файл можно на странице инструкции.</small>
                    </div>
                @endif
            </div>
        </div>

        <div class="ins-save">
            <button type="submit" class="btn-primary-custom">
                <i class="bi bi-check-lg"></i> Сохранить
            </button>
        </div>
    </form>
</div>

<style>
.ins-form-page{max-width:900px;}
.ins-head-actions{display:flex;gap:10px;align-items:center;}
.ins-head-actions form{margin:0;}

.ins-editor-toolbar{display:flex;gap:4px;align-items:center;flex-wrap:wrap;
  background:var(--bg-secondary);border:1px solid var(--border);
  border-bottom:none;border-radius:10px 10px 0 0;padding:6px 8px;}
.ins-editor-toolbar button{min-width:32px;height:30px;border-radius:8px;border:1px solid transparent;
  background:transparent;color:var(--text-secondary);cursor:pointer;font-size:14px;}
.ins-editor-toolbar button:hover{background:var(--bg-card-hover);color:var(--text-primary);}
.ins-toolbar-sep{width:1px;height:18px;background:var(--border);margin:0 4px;}

.ins-editor{min-height:320px;max-height:60vh;overflow-y:auto;
  background:var(--bg-secondary);border:1px solid var(--border);border-radius:0 0 10px 10px;
  padding:14px 16px;color:var(--text-primary);font-size:15px;line-height:1.65;outline:none;}
.ins-editor:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow);}
.ins-editor h3{font-size:17px;font-weight:700;margin:18px 0 8px;}
.ins-editor ul,.ins-editor ol{padding-left:22px;margin:0 0 12px;}
.ins-editor p{margin:0 0 10px;}
.ins-editor:empty::before{content:attr(data-placeholder);color:var(--text-muted);}

.ins-upload{margin-top:20px;}
.ins-existing{margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;}
.ins-chip{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;
  color:var(--text-secondary);background:var(--bg-secondary);
  border:1px solid var(--border);border-radius:6px;padding:5px 10px;}

.ins-save{margin-top:20px;}
</style>

<script>
// Простой редактор: заголовки, списки и выделение — всё, чем оформляют
// инструкцию. Тяжёлую библиотеку ради этого тащить незачем.
(function () {
    const editor = document.getElementById('insEditor');
    const hidden = document.getElementById('insBody');
    if (!editor || !hidden) return;

    editor.setAttribute('data-placeholder', 'Например: 1. Проверить корты. 2. Открыть смену в CRM…');

    document.querySelectorAll('.ins-editor-toolbar button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            editor.focus();
            document.execCommand(btn.dataset.cmd, false, btn.dataset.value || null);
        });
    });

    // Вставка из буфера — только текстом: иначе вместе с текстом приезжают
    // чужие шрифты, цвета и таблицы, и инструкция выглядит как каша.
    editor.addEventListener('paste', function (e) {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, text);
    });

    editor.closest('form').addEventListener('submit', function () {
        hidden.value = editor.innerHTML.trim();
    });
})();
</script>
@endsection
