@extends('layouts.app')
@section('title', 'Импорт клиентов')
@section('content')

<div class="imp-container">
    <header class="imp-header">
        <div class="imp-title-block">
            <a href="{{ route('club.clients.index') }}" class="imp-back" aria-label="Назад">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h1 class="imp-title">Импорт клиентов из Excel</h1>
                <p class="imp-subtitle">Клуб: {{ $club->name }}</p>
            </div>
        </div>
    </header>

    @if($report ?? null)
        @if(($report['success'] ?? false))
            <div class="imp-report imp-report-ok">
                <div class="imp-report-title">
                    <i class="bi bi-check-circle-fill"></i> Импорт завершён
                </div>
                <div class="imp-stats">
                    <div class="imp-stat imp-stat-created">
                        <span class="imp-stat-num">{{ $report['created'] }}</span>
                        <span class="imp-stat-label">создано</span>
                    </div>
                    <div class="imp-stat imp-stat-updated">
                        <span class="imp-stat-num">{{ $report['updated'] }}</span>
                        <span class="imp-stat-label">обновлено</span>
                    </div>
                    <div class="imp-stat imp-stat-skipped">
                        <span class="imp-stat-num">{{ count($report['skipped_no_phone']) + count($report['skipped_duplicate']) }}</span>
                        <span class="imp-stat-label">пропущено</span>
                    </div>
                </div>
            </div>

            @if(count($report['skipped_no_phone']))
                <div class="imp-skipped-block">
                    <h3 class="imp-skipped-title">
                        <i class="bi bi-telephone-x"></i>
                        Пропущены — без телефона ({{ count($report['skipped_no_phone']) }})
                    </h3>
                    <table class="imp-table">
                        <thead><tr><th>Строка</th><th>Имя</th><th>Email</th><th>Карта</th></tr></thead>
                        <tbody>
                            @foreach($report['skipped_no_phone'] as $row)
                                <tr>
                                    <td class="imp-row-num">{{ $row['row'] }}</td>
                                    <td>{{ $row['name'] }}</td>
                                    <td>{{ $row['email'] ?: '—' }}</td>
                                    <td>{{ $row['card'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if(count($report['skipped_duplicate']))
                <div class="imp-skipped-block">
                    <h3 class="imp-skipped-title">
                        <i class="bi bi-files"></i>
                        Пропущены — дубль телефона в файле ({{ count($report['skipped_duplicate']) }})
                    </h3>
                    <table class="imp-table">
                        <thead><tr><th>Строка</th><th>Имя</th><th>Телефон</th><th>Первая строка</th></tr></thead>
                        <tbody>
                            @foreach($report['skipped_duplicate'] as $row)
                                <tr>
                                    <td class="imp-row-num">{{ $row['row'] }}</td>
                                    <td>{{ $row['name'] }}</td>
                                    <td>{{ $row['phone'] }}</td>
                                    <td class="imp-row-num">{{ $row['first_row'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @else
            <div class="imp-report imp-report-err">
                <i class="bi bi-exclamation-triangle-fill"></i>
                {{ $report['error'] ?? 'Не удалось обработать файл' }}
            </div>
        @endif
    @endif

    <form method="POST" action="{{ route('club.clients.import') }}" enctype="multipart/form-data" class="imp-form">
        @csrf
        <div class="imp-instructions">
            <h2 class="imp-section-title">Что нужно знать перед загрузкой</h2>
            <ul class="imp-rules">
                <li>Поддерживаются <b>XLS, XLSX, CSV</b>. Размер — до 10 МБ.</li>
                <li>В файле должна быть первая строка-шапка. Колонки определяются по названиям:
                    <code>ФИО / Имя</code>, <code>Телефон</code>, <code>E-mail</code>, <code>Номер карты</code>, <code>Теги</code>.
                </li>
                <li><b>Телефон — обязателен.</b> Записи без телефона будут пропущены и показаны в отчёте.</li>
                <li>Если клиент с таким же телефоном <b>уже есть</b> в клубе — карточка <b>обновится</b> (заполнятся пустые поля), новая запись не создастся.</li>
                <li>Если в файле один телефон встречается несколько раз — будет создана только первая, остальные попадут в отчёт.</li>
                <li>Иностранные номера (российские, испанские и т.д.) сохраняются как есть.</li>
            </ul>
        </div>

        @if($errors->any())
            <div class="imp-report imp-report-err">
                <i class="bi bi-exclamation-triangle-fill"></i>
                {{ $errors->first() }}
            </div>
        @endif

        <div class="imp-upload">
            <label for="impFile" class="imp-file-label">
                <i class="bi bi-cloud-upload"></i>
                <span id="impFileName">Выберите файл Excel</span>
            </label>
            <input type="file" name="file" id="impFile" accept=".xls,.xlsx,.csv" required
                   onchange="document.getElementById('impFileName').textContent = this.files[0]?.name || 'Выберите файл Excel'">
            <button type="submit" class="imp-btn-submit">
                <i class="bi bi-arrow-right-circle"></i> Импортировать
            </button>
        </div>
    </form>
</div>

<style>
    .imp-container { max-width: 960px; margin: 0 auto; padding: 32px 24px; color: #f4f4f5; }
    .imp-header { margin-bottom: 24px; }
    .imp-title-block { display: flex; align-items: center; gap: 16px; }
    .imp-back {
        display: flex; align-items: center; justify-content: center;
        width: 40px; height: 40px; border-radius: 10px;
        background: #18181b; border: 1px solid #27272a; color: #a1a1aa;
        text-decoration: none; transition: all .2s;
    }
    .imp-back:hover { background: #27272a; color: #f4f4f5; }
    .imp-title { font-size: 24px; font-weight: 700; margin: 0; color: #f4f4f5; }
    .imp-subtitle { font-size: 13px; color: #a1a1aa; margin: 4px 0 0; }

    .imp-report {
        padding: 16px 20px; border-radius: 12px; margin-bottom: 20px;
        display: flex; align-items: center; gap: 12px; font-size: 14px;
    }
    .imp-report-ok {
        background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.25); color: #4ade80;
        flex-direction: column; align-items: stretch;
    }
    .imp-report-err {
        background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25); color: #f87171;
    }
    .imp-report-title { font-weight: 600; font-size: 15px; }
    .imp-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 12px; }
    .imp-stat {
        background: rgba(0,0,0,.2); border-radius: 10px; padding: 14px;
        display: flex; flex-direction: column; gap: 4px;
    }
    .imp-stat-num { font-size: 28px; font-weight: 700; }
    .imp-stat-label { font-size: 12px; color: #a1a1aa; text-transform: uppercase; letter-spacing: .4px; }
    .imp-stat-created .imp-stat-num { color: #4ade80; }
    .imp-stat-updated .imp-stat-num { color: #60a5fa; }
    .imp-stat-skipped .imp-stat-num { color: #facc15; }

    .imp-skipped-block { margin-bottom: 24px; }
    .imp-skipped-title {
        font-size: 14px; font-weight: 600; color: #facc15;
        display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
    }
    .imp-table {
        width: 100%; border-collapse: collapse; background: #18181b;
        border: 1px solid #27272a; border-radius: 12px; overflow: hidden; font-size: 13px;
    }
    .imp-table th { text-align: left; padding: 10px 14px; background: #0f0f10; color: #a1a1aa; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
    .imp-table td { padding: 10px 14px; border-top: 1px solid #27272a; color: #d4d4d8; }
    .imp-row-num { color: #71717a; font-variant-numeric: tabular-nums; }

    .imp-form { background: #18181b; border: 1px solid #27272a; border-radius: 14px; padding: 24px; }
    .imp-section-title { font-size: 15px; font-weight: 600; margin: 0 0 12px; color: #f4f4f5; }
    .imp-rules { margin: 0 0 24px; padding-left: 18px; font-size: 14px; line-height: 1.7; color: #d4d4d8; }
    .imp-rules li { margin-bottom: 6px; }
    .imp-rules code { background: rgba(255,255,255,.05); padding: 1px 6px; border-radius: 4px; font-size: 12px; color: #a3e635; }

    .imp-upload { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    #impFile { display: none; }
    .imp-file-label {
        flex: 1; min-width: 200px;
        display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; background: #0f0f10; border: 1px dashed #3f3f46;
        border-radius: 10px; color: #a1a1aa; cursor: pointer; transition: all .2s;
        font-size: 14px;
    }
    .imp-file-label:hover { border-color: #4ade80; color: #f4f4f5; }
    .imp-btn-submit {
        padding: 12px 22px; background: #22c55e; color: #052e16; border: none;
        border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer;
        display: flex; align-items: center; gap: 8px; transition: background .2s;
    }
    .imp-btn-submit:hover { background: #16a34a; }
</style>

@endsection
