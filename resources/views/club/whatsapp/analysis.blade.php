{{-- Разбор дня переписки: цифры считаем сами, объяснение даёт Claude. --}}
@extends('layouts.app')

@section('title', 'WhatsApp — разбор дня')

@section('content')
@php
    $tz = config('app.schedule_timezone', 'Asia/Almaty');
    $human = fn ($m) => $m === null ? '—' : \App\Support\WhatsappSla::humanMinutes((int) $m);
    $report = $analysis?->report ?? [];
@endphp
<div class="wa-wrap">

    <div class="wa-head">
        <a href="{{ route('club.whatsapp.index') }}" class="wa-back"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1>Разбор дня</h1>
            <div class="wa-sub">
                {{ $club->name }} · {{ $date->locale('ru')->translatedFormat('j F Y, l') }}
            </div>
        </div>
    </div>

    <form method="GET" class="wa-picker">
        <input type="date" name="date" value="{{ $date->toDateString() }}"
               max="{{ now($tz)->toDateString() }}">
        <button type="submit" class="wa-btn ghost">Показать</button>
        <div class="wa-quick">
            @foreach($days as $day)
                @php $d = \Carbon\Carbon::parse($day, $tz); @endphp
                <a href="{{ route('club.whatsapp.analysis', ['date' => $day]) }}"
                   class="{{ $day === $date->toDateString() ? 'on' : '' }}">
                    {{ $d->locale('ru')->translatedFormat('j M') }}
                </a>
            @endforeach
        </div>
    </form>

    @if(session('error'))
        <div class="wa-flash bad"><i class="bi bi-exclamation-triangle"></i> {{ session('error') }}</div>
    @endif
    @if(session('success'))
        <div class="wa-flash ok"><i class="bi bi-check2-circle"></i> {{ session('success') }}</div>
    @endif

    <div class="wa-stats">
        <div class="wa-stat">
            <b>{{ $metrics['dialogs'] }}</b>
            <span>{{ trans_choice('диалог|диалога|диалогов', $metrics['dialogs']) }},
                из них новых {{ $metrics['new_contacts'] }}</span>
        </div>
        <div class="wa-stat">
            <b>{{ $human($metrics['median']) }}</b>
            <span>обычный ответ (медиана), худший — {{ $human($metrics['worst']) }}</span>
        </div>
        <div class="wa-stat {{ $metrics['slow'] ? 'bad' : '' }}">
            <b>{{ $metrics['slow'] }}</b>
            <span>ответов дольше {{ $metrics['threshold'] }} мин</span>
        </div>
        <div class="wa-stat {{ $metrics['unanswered'] ? 'bad' : '' }}">
            <b>{{ $metrics['unanswered'] }}</b>
            <span>обращений без ответа</span>
        </div>
        <div class="wa-stat">
            <b>{{ $metrics['booked'] }}</b>
            <span>из написавших оформили бронь в этот день</span>
        </div>
        <div class="wa-stat">
            <b>{{ $metrics['incoming'] }}</b>
            <span>входящих сообщений, {{ $metrics['outgoing'] }} наших</span>
        </div>
    </div>

    @if($metrics['dialogs'] === 0)
        <div class="wa-empty">
            <i class="bi bi-calendar-x"></i>
            <p>За этот день переписки нет</p>
            <span>Выберите другую дату.</span>
        </div>
    @else
        <form method="POST" action="{{ route('club.whatsapp.analysis.run') }}" class="wa-run">
            @csrf
            <input type="hidden" name="date" value="{{ $date->toDateString() }}">
            @if($analysis)
                <input type="hidden" name="force" value="1">
                <button type="submit" class="wa-btn ghost">
                    <i class="bi bi-arrow-clockwise"></i> Пересобрать разбор
                </button>
                <span class="wa-when">
                    разобрано {{ $analysis->generated_at?->timezone($tz)->locale('ru')->translatedFormat('j M, H:i') }},
                    модель {{ $analysis->model }}
                </span>
            @else
                <button type="submit" class="wa-btn">
                    <i class="bi bi-stars"></i> Сделать анализ
                </button>
                <span class="wa-when">Claude прочитает все диалоги дня — это занимает до минуты</span>
            @endif
        </form>
    @endif

    @if($analysis)
        @if(!empty($report['verdict']))
            <div class="wa-card verdict">
                <div class="wa-card-head"><i class="bi bi-flag"></i> Вердикт</div>
                <p>{{ $report['verdict'] }}</p>
            </div>
        @endif

        @if(!empty($report['lost_sales']))
            <div class="wa-card">
                <div class="wa-card-head bad"><i class="bi bi-cash-stack"></i> Где не продали</div>
                @foreach($report['lost_sales'] as $item)
                    <div class="wa-row">
                        <div class="wa-row-top">
                            <span class="wa-chip">…{{ $item['phone'] }}</span>
                            <b>{{ $item['what'] }}</b>
                        </div>
                        <p>{{ $item['why'] }}</p>
                        @if($item['quote'])
                            <blockquote>«{{ $item['quote'] }}»</blockquote>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if(!empty($report['slow']))
            <div class="wa-card">
                <div class="wa-card-head warn"><i class="bi bi-hourglass-split"></i> Долго отвечали</div>
                @foreach($report['slow'] as $item)
                    <div class="wa-row compact">
                        <span class="wa-chip">…{{ $item['phone'] }}</span>
                        <b>{{ $item['waited'] }}</b>
                        <span>{{ $item['what'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if(!empty($report['quality']))
            <div class="wa-card">
                <div class="wa-card-head"><i class="bi bi-chat-quote"></i> Качество общения</div>
                @foreach($report['quality'] as $item)
                    <div class="wa-row">
                        <div class="wa-row-top"><b>{{ $item['issue'] }}</b></div>
                        @if($item['example'])
                            <blockquote>«{{ $item['example'] }}»</blockquote>
                        @endif
                        @if($item['fix'])
                            <p class="fix"><i class="bi bi-arrow-return-right"></i> {{ $item['fix'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if(!empty($report['automation']))
            <div class="wa-card">
                <div class="wa-card-head"><i class="bi bi-robot"></i> Что можно отвечать автоматически</div>
                <p class="wa-card-note">
                    Повторяющиеся вопросы с однозначным ответом. Готовый текст можно отдать боту —
                    остальное по-прежнему за менеджером.
                </p>
                @foreach($report['automation'] as $item)
                    <div class="wa-row">
                        <div class="wa-row-top">
                            <b>{{ $item['question'] }}</b>
                            @if($item['times'])
                                <span class="wa-chip">{{ $item['times'] }} раз за день</span>
                            @endif
                        </div>
                        @if($item['answer'])
                            <blockquote class="answer">{{ $item['answer'] }}</blockquote>
                        @endif
                        @if($item['caution'])
                            <p class="caution"><i class="bi bi-exclamation-triangle"></i> {{ $item['caution'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if(!empty($report['actions']))
            <div class="wa-card">
                <div class="wa-card-head ok"><i class="bi bi-list-check"></i> Что сделать завтра</div>
                <ol class="wa-actions">
                    @foreach($report['actions'] as $action)
                        <li>{{ $action }}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        @if(!empty($report['good']))
            <div class="wa-card">
                <div class="wa-card-head ok"><i class="bi bi-hand-thumbs-up"></i> Что было хорошо</div>
                <ul class="wa-actions">
                    @foreach($report['good'] as $good)
                        <li>{{ $good }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>

<style>
.wa-wrap{max-width:900px;margin:0 auto;padding:20px 16px 40px;color:#f4f4f5;
  --card:#16161a;--card2:#1e1e24;--line:#27272a;--wa:#25d366;--bad:#f87171;--warn:#fbbf24;--t2:#a1a1aa;--t3:#71717a;}
.wa-head{display:flex;align-items:center;gap:14px;margin-bottom:18px;}
.wa-head h1{font-size:22px;font-weight:800;margin:0;}
.wa-back{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;
  background:var(--card);border:1px solid var(--line);border-radius:10px;color:var(--t2);text-decoration:none;}
.wa-sub{color:var(--t3);font-size:13px;margin-top:2px;}

.wa-picker{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.wa-picker input[type=date]{background:var(--card);border:1px solid var(--line);border-radius:10px;
  padding:9px 12px;color:#f3f3f5;font-size:13px;color-scheme:dark;}
.wa-quick{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto;}
.wa-quick a{padding:7px 11px;border-radius:9px;background:var(--card);border:1px solid var(--line);
  color:var(--t2);text-decoration:none;font-size:12px;}
.wa-quick a.on{background:rgba(37,211,102,.14);border-color:rgba(37,211,102,.35);color:var(--wa);font-weight:700;}

.wa-btn{display:inline-flex;align-items:center;gap:8px;border:none;cursor:pointer;
  background:var(--wa);color:#07120a;font-weight:800;font-size:13.5px;padding:11px 18px;border-radius:11px;}
.wa-btn.ghost{background:var(--card);border:1px solid var(--line);color:var(--t2);font-weight:600;}
.wa-run{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:16px 0;}
.wa-when{font-size:12px;color:var(--t3);}

.wa-flash{display:flex;align-items:center;gap:8px;padding:11px 14px;border-radius:11px;
  font-size:13px;margin-bottom:14px;}
.wa-flash.ok{background:rgba(37,211,102,.12);color:var(--wa);}
.wa-flash.bad{background:rgba(248,113,113,.12);color:var(--bad);}

.wa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;}
.wa-stat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;}
.wa-stat b{display:block;font-size:22px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums;}
.wa-stat span{font-size:12px;color:var(--t3);line-height:1.4;display:block;margin-top:2px;}
.wa-stat.bad b{color:var(--bad);}

.wa-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-top:14px;}
.wa-card-head{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800;
  letter-spacing:.5px;text-transform:uppercase;color:var(--t2);margin-bottom:12px;}
.wa-card-head.bad{color:var(--bad);}
.wa-card-head.warn{color:var(--warn);}
.wa-card-head.ok{color:var(--wa);}
.wa-card.verdict p{margin:0;font-size:14.5px;line-height:1.6;}
.wa-row{padding:12px 0;border-top:1px solid var(--line);}
.wa-card .wa-row:first-of-type{border-top:none;padding-top:0;}
.wa-row.compact{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;font-size:13.5px;}
.wa-row-top{display:flex;align-items:baseline;gap:9px;flex-wrap:wrap;}
.wa-row-top b{font-size:14px;}
.wa-row p{margin:6px 0 0;font-size:13.5px;color:var(--t2);line-height:1.55;}
.wa-row p.fix{color:var(--wa);}
.wa-chip{font-size:11px;font-weight:800;padding:3px 8px;border-radius:7px;
  background:var(--card2);color:var(--t2);font-variant-numeric:tabular-nums;}
blockquote{margin:8px 0 0;padding:8px 12px;border-left:2px solid var(--line);
  background:var(--card2);border-radius:0 8px 8px 0;font-size:13px;color:var(--t2);font-style:italic;}
.wa-card-note{margin:-4px 0 12px;font-size:12.5px;color:var(--t3);line-height:1.5;}
blockquote.answer{border-left-color:var(--wa);font-style:normal;color:#e7e7ea;}
.wa-row p.caution{color:var(--warn);}
.wa-actions{margin:0;padding-left:20px;}
.wa-actions li{font-size:13.5px;line-height:1.6;margin-bottom:6px;}

.wa-empty{text-align:center;padding:50px 20px;background:var(--card);
  border:1px dashed var(--line);border-radius:14px;color:var(--t3);margin-top:14px;}
.wa-empty i{font-size:28px;display:block;margin-bottom:10px;opacity:.6;}
.wa-empty p{margin:0 0 6px;font-size:15px;font-weight:600;color:#f3f3f5;}
</style>

<script>
// Запрос к модели идёт до минуты — показываем, что кнопка сработала.
document.querySelector('.wa-run')?.addEventListener('submit', (e) => {
    const button = e.target.querySelector('button');
    button.disabled = true;
    button.innerHTML = '<i class="bi bi-hourglass-split"></i> Читаю переписку…';
});
</script>
@endsection
