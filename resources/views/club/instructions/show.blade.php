{{-- Одна должностная инструкция: текст и файлы. --}}
@extends('layouts.app')

@section('title', $instruction->title)

@section('content')
<div class="ins-show">

    <div class="page-header">
        <div>
            <h2>{{ $instruction->title }}</h2>
            <p>
                {{ $instruction->section->title }}
                @if($instruction->updated_at)
                    · обновлено {{ $instruction->updated_at->timezone(config('app.schedule_timezone', 'Asia/Almaty'))->locale('ru')->translatedFormat('j F Y, H:i') }}
                    @if($instruction->editor) · {{ $instruction->editor->name }} @endif
                @endif
            </p>
        </div>
        <div class="ins-head-actions">
            <a href="{{ route('club.instructions.index') }}" class="btn-outline-custom">
                <i class="bi bi-arrow-left"></i> Ко всем
            </a>
            @if($canEdit)
                <a href="{{ route('club.instructions.edit', $instruction) }}" class="btn-primary-custom">
                    <i class="bi bi-pencil"></i> Править
                </a>
            @endif
        </div>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif

    <div class="card-dark">
        <div class="card-body">
            @if(trim(strip_tags((string) $instruction->body)) === '')
                <p class="ins-blank">Текст инструкции пока не заполнен.</p>
            @else
                {{-- Разметку чистит контроллер при сохранении. --}}
                <div class="ins-text">{!! $instruction->body !!}</div>
            @endif
        </div>
    </div>

    @if($instruction->files->isNotEmpty())
        <div class="card-dark" style="margin-top:16px">
            <div class="card-header"><h5><i class="bi bi-paperclip"></i> Файлы</h5></div>
            <div class="card-body ins-files-body">
                @foreach($instruction->files as $file)
                    <div class="ins-file">
                        @if($file->is_image)
                            <a href="{{ $file->path }}" target="_blank" rel="noopener" class="ins-file-thumb">
                                <img src="{{ $file->path }}" alt="{{ $file->name }}" loading="lazy">
                            </a>
                        @else
                            <a href="{{ $file->path }}" target="_blank" rel="noopener" class="ins-file-doc">
                                <i class="bi bi-file-earmark-pdf"></i>
                            </a>
                        @endif

                        <div class="ins-file-info">
                            <a href="{{ $file->path }}" target="_blank" rel="noopener">{{ $file->name }}</a>
                            <span>{{ $file->humanSize() }}</span>
                        </div>

                        @if($canEdit)
                            <form method="POST" action="{{ route('club.instructions.files.destroy', $file) }}"
                                  onsubmit="return confirm('Удалить файл?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="ins-icon" title="Удалить файл"><i class="bi bi-trash"></i></button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>

<style>
.ins-show{max-width:900px;}
.ins-head-actions{display:flex;gap:10px;}
.ins-blank{color:var(--text-secondary);margin:0;}

/* Текст инструкции: читают в спешке, поэтому крупнее и с воздухом. */
.ins-text{color:var(--text-primary);font-size:15px;line-height:1.65;}
.ins-text h3{font-size:17px;font-weight:700;margin:22px 0 8px;}
.ins-text h4{font-size:15px;font-weight:700;margin:18px 0 6px;}
.ins-text p{margin:0 0 12px;}
.ins-text ul,.ins-text ol{margin:0 0 14px;padding-left:22px;}
.ins-text li{margin-bottom:6px;}
.ins-text a{color:var(--accent);}
.ins-text img{max-width:100%;border-radius:10px;margin:8px 0;}
.ins-text blockquote{margin:12px 0;padding:10px 14px;border-left:3px solid var(--accent);
  background:var(--bg-secondary);border-radius:0 10px 10px 0;color:var(--text-secondary);}
.ins-text > *:first-child{margin-top:0;}
.ins-text > *:last-child{margin-bottom:0;}

.ins-files-body{display:flex;flex-direction:column;gap:12px;}
.ins-file{display:flex;align-items:center;gap:12px;}
.ins-file form{margin:0;}
.ins-file-thumb img{width:56px;height:56px;object-fit:cover;border-radius:10px;display:block;}
.ins-file-doc{width:56px;height:56px;border-radius:10px;background:var(--bg-secondary);
  display:flex;align-items:center;justify-content:center;color:var(--text-secondary);font-size:22px;}
.ins-file-info{flex:1;min-width:0;}
.ins-file-info a{display:block;color:var(--text-primary);text-decoration:none;font-size:14px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ins-file-info a:hover{color:var(--accent);}
.ins-file-info span{display:block;color:var(--text-muted);font-size:12px;margin-top:2px;}
.ins-icon{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);
  background:transparent;color:var(--text-secondary);display:inline-flex;
  align-items:center;justify-content:center;cursor:pointer;}
.ins-icon:hover{color:#ef4444;border-color:#ef4444;}
</style>
@endsection
