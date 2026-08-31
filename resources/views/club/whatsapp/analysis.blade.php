{{-- Разбор дня переписки: цифры считаем сами, объяснение даёт Claude. --}}
@extends('layouts.app')

@section('title', 'WhatsApp — разбор дня')

@section('content')
@php
    $tz = config('app.schedule_timezone', 'Asia/Almaty');
    $human = fn ($m) => $m === null ? '—' : \App\Support\WhatsappSla::humanMinutes((int) $m);
    $report = $analysis?->report ?? [];

    // Модель пишет хвост номера как придётся: «0016», «…0016», «+7…16».
    // Приводим к четырём цифрам — по ним ищем человека.
    $tail = function ($raw) {
        $digits = preg_replace('/\D/', '', (string) $raw);

        return strlen((string) $digits) >= 4 ? substr((string) $digits, -4) : '';
    };

    // Находки всех видов в одной ленте: разбирают их подряд, а не по
    // разделам, и важность важнее рубрики.
    $findings = [];

    foreach ($report['lost_sales'] ?? [] as $item) {
        $findings[] = [
            'kind' => 'lost', 'label' => 'Потеря', 'phone' => $tail($item['phone']),
            'title' => $item['what'], 'text' => $item['why'], 'quote' => $item['quote'],
            'fix' => '', 'meta' => '',
        ];
    }

    foreach ($report['slow'] ?? [] as $item) {
        $findings[] = [
            'kind' => 'slow', 'label' => 'Долго', 'phone' => $tail($item['phone']),
            'title' => $item['what'], 'text' => '', 'quote' => '',
            'fix' => '', 'meta' => $item['waited'],
        ];
    }

    foreach ($report['quality'] ?? [] as $item) {
        $findings[] = [
            'kind' => 'quality', 'label' => 'Качество', 'phone' => '',
            'title' => $item['issue'], 'text' => '', 'quote' => $item['example'],
            'fix' => $item['fix'], 'meta' => '',
        ];
    }

    foreach ($report['good'] ?? [] as $item) {
        $findings[] = [
            'kind' => 'good', 'label' => 'Хорошо', 'phone' => '',
            'title' => $item, 'text' => '', 'quote' => '', 'fix' => '', 'meta' => '',
        ];
    }

    // Высота столбика — доля обращений этого часа от самого занятого.
    $peak = collect($hours)->max('requests') ?: 1;
    $conversion = $metrics['dialogs'] ? round($metrics['booked'] / $metrics['dialogs'] * 100) : 0;
@endphp

<div class="an-wrap">

    <div class="an-head">
        <a href="{{ route('club.whatsapp.index') }}" class="an-back"><i class="bi bi-chevron-left"></i></a>
        <div>
            <h1>Разбор дня</h1>
            <div class="an-sub">
                {{ $club->name }} · {{ $date->locale('ru')->translatedFormat('j F Y, l') }}
                @if($analysis)
                    · разобрано {{ $analysis->generated_at?->timezone($tz)->locale('ru')->translatedFormat('j M, H:i') }}
                @endif
            </div>
        </div>

        <div class="an-days">
            @foreach($days as $day)
                @php $d = \Carbon\Carbon::parse($day, $tz); @endphp
                <a href="{{ route('club.whatsapp.analysis', ['date' => $day]) }}"
                   class="an-day {{ $day === $date->toDateString() ? 'on' : '' }}">
                    {{ $d->locale('ru')->translatedFormat('j M') }}
                </a>
            @endforeach
        </div>

        <form method="GET" class="an-picker">
            <input type="date" name="date" value="{{ $date->toDateString() }}"
                   max="{{ now($tz)->toDateString() }}" onchange="this.form.submit()">
        </form>

        @if($metrics['dialogs'] > 0)
            <form method="POST" action="{{ route('club.whatsapp.analysis.run') }}" class="an-run">
                @csrf
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                @if($analysis)
                    <input type="hidden" name="force" value="1">
                    <button type="submit" class="an-btn" title="Модель перечитает диалоги заново">
                        <i class="bi bi-arrow-clockwise"></i> Пересобрать
                    </button>
                @else
                    <button type="submit" class="an-btn pri">
                        <i class="bi bi-stars"></i> Разобрать день
                    </button>
                @endif
            </form>
        @endif
    </div>

    @if(session('error'))
        <div class="an-flash bad"><i class="bi bi-exclamation-triangle"></i> {{ session('error') }}</div>
    @endif
    @if(session('success'))
        <div class="an-flash ok"><i class="bi bi-check2-circle"></i> {{ session('success') }}</div>
    @endif

    @if($metrics['dialogs'] === 0)
        <div class="an-empty">
            <i class="bi bi-calendar-x"></i>
            <p>За этот день переписки нет</p>
            <span>Выберите другую дату.</span>
        </div>
    @else

        {{-- ── Главное: вывод словами и две цифры, которые решают ──────── --}}
        <div class="an-top">
            <div class="an-card an-verdict">
                <div class="an-lbl"><i class="bi bi-flag"></i> Главное за день</div>
                @if(!empty($report['verdict']))
                    <p>{{ $report['verdict'] }}</p>
                @else
                    <p class="an-muted">
                        Цифры дня посчитаны, но словами день ещё не разобран.
                        Claude прочитает все диалоги и покажет, где упустили продажу,
                        где ответили формально и что поправить завтра — это занимает до минуты.
                    </p>
                @endif
            </div>

            <div class="an-card an-big">
                <div class="an-lbl">Остались без ответа</div>
                <div class="an-num {{ $metrics['unanswered'] ? 'bad' : 'ok' }}">{{ $metrics['unanswered'] }}</div>
                <div class="an-cap">
                    {{ trans_choice('обращение|обращения|обращений', $metrics['unanswered']) }}
                    из {{ $metrics['requests'] }} за день@if($metrics['slow']), ещё {{ $metrics['slow'] }}
                    {{ trans_choice('ответ|ответа|ответов', $metrics['slow']) }}
                    дольше {{ $metrics['threshold'] }} мин@endif.
                </div>
            </div>

            <div class="an-card an-big">
                <div class="an-lbl">Дошли до брони</div>
                <div class="an-num {{ $metrics['booked'] ? 'ok' : '' }}">{{ $metrics['booked'] }}</div>
                <div class="an-cap">
                    {{ $conversion }}% написавших. Обычный ответ — {{ $human($metrics['median']) }},
                    худший — {{ $human($metrics['worst']) }}.
                </div>
            </div>
        </div>

        {{-- ── Шкала дня: медиана прячет провалы, часы их показывают ──── --}}
        <div class="an-card an-tl">
            <div class="an-lbl"><i class="bi bi-clock-history"></i> Где день просел</div>

            <div class="an-bars">
                @foreach($hours as $h)
                    @php
                        $height = $h['requests'] ? max(12, round($h['requests'] / $peak * 100)) : 4;
                        $hint = $h['requests'] === 0
                            ? $h['label'] . ':00 — обращений не было'
                            : $h['label'] . ':00 — ' . $h['requests'] . ' '
                                . trans_choice('обращение|обращения|обращений', $h['requests'])
                                . ($h['worst'] !== null ? ', худший ответ ' . $human($h['worst']) : '')
                                . ($h['unanswered'] ? ', без ответа ' . $h['unanswered'] : '');
                    @endphp
                    <div class="an-bar {{ $h['state'] }} {{ $h['work'] ? '' : 'off' }}" title="{{ $hint }}">
                        <div class="an-bar-val">{{ $h['requests'] ?: '' }}</div>
                        <div class="an-bar-track">
                            <div class="an-bar-fill" style="height:{{ $height }}%"></div>
                        </div>
                        <div class="an-bar-hour">{{ $h['label'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="an-tl-foot">
                <div class="an-legend">
                    <span><i class="an-dot ok"></i> уложились в {{ $metrics['threshold'] }} мин</span>
                    <span><i class="an-dot slow"></i> отвечали дольше</span>
                    <span><i class="an-dot bad"></i> ждали больше часа или не ответили</span>
                    <span><i class="an-dot off"></i> клуб закрыт</span>
                </div>
                <div class="an-meta">
                    {{ $metrics['dialogs'] }} {{ trans_choice('диалог|диалога|диалогов', $metrics['dialogs']) }},
                    {{ $metrics['new_contacts'] }} новых ·
                    {{ $metrics['incoming'] }} входящих, {{ $metrics['outgoing'] }} наших ·
                    рабочие часы {{ $metrics['work_hours'] }}
                    @if($outside)
                        · ещё {{ $outside }}
                        {{ trans_choice('обращение|обращения|обращений', $outside) }} ночью, вне шкалы
                    @endif
                </div>
            </div>
        </div>

        @if(!$analysis)
            <div class="an-cta">
                <div>
                    <b>Разбора за этот день ещё нет</b>
                    <span>Claude прочитает {{ $metrics['dialogs'] }} {{ trans_choice('диалог|диалога|диалогов', $metrics['dialogs']) }} и покажет, где потеряли клиента.</span>
                </div>
                <form method="POST" action="{{ route('club.whatsapp.analysis.run') }}" class="an-run">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                    <button type="submit" class="an-btn pri"><i class="bi bi-stars"></i> Разобрать день</button>
                </form>
            </div>
        @else

            {{-- ── Находки: свёрнуты в строки, подробности по клику ───── --}}
            @if($findings)
                <div class="an-card">
                    <div class="an-sec">
                        <i class="bi bi-list-check"></i>
                        <h2>Находки дня</h2>
                        <span class="an-cnt">{{ count($findings) }}</span>
                    </div>

                    @foreach($findings as $i => $find)
                        @php
                            $person = $find['phone'] ? ($people[$find['phone']] ?? null) : null;
                            // Хвост номера без имени — это лучше, чем увести не в тот диалог.
                            $who = $person['name'] ?? '';
                            if ($who === '' && $find['phone']) {
                                $who = '…' . $find['phone'];
                            }
                            $letter = $who !== '' ? mb_strtoupper(mb_substr($who, 0, 1)) : '';
                            $hasBody = $find['text'] || $find['quote'] || $find['fix'];
                        @endphp
                        <details class="an-find" {{ $i === 0 && $hasBody ? 'open' : '' }}>
                            <summary>
                                <span class="an-pill {{ $find['kind'] }}">{{ $find['label'] }}</span>

                                @if($who !== '')
                                    <span class="an-ava">{{ $letter ?: '?' }}</span>
                                    <span class="an-who">{{ $who }}</span>
                                @endif

                                <span class="an-what">{{ $find['title'] }}</span>

                                @if($find['meta'])
                                    <span class="an-wait">{{ $find['meta'] }}</span>
                                @endif

                                @if($person)
                                    <a class="an-open" href="{{ route('club.whatsapp.show', $person['phone']) }}"
                                       title="Открыть переписку">
                                        <i class="bi bi-whatsapp"></i> Чат
                                    </a>
                                @endif

                                <i class="bi bi-chevron-down an-caret {{ $hasBody ? '' : 'hide' }}"></i>
                            </summary>

                            @if($hasBody)
                                <div class="an-body">
                                    @if($find['text'])<p>{{ $find['text'] }}</p>@endif
                                    @if($find['quote'])<div class="an-quote">«{{ $find['quote'] }}»</div>@endif
                                    @if($find['fix'])
                                        <div class="an-fix"><i class="bi bi-arrow-return-right"></i> {{ $find['fix'] }}</div>
                                    @endif
                                </div>
                            @endif
                        </details>
                    @endforeach
                </div>
            @endif

            {{-- ── Что делать: завтра руками и что можно отдать роботу ── --}}
            <div class="an-bottom">
                @if(!empty($report['actions']))
                    <div class="an-card">
                        <div class="an-sec">
                            <i class="bi bi-check2-square" style="color:var(--wa)"></i>
                            <h2>Что изменить завтра</h2>
                        </div>
                        <ol class="an-todo">
                            @foreach($report['actions'] as $action)
                                <li>{{ $action }}</li>
                            @endforeach
                        </ol>
                    </div>
                @endif

                @if(!empty($report['automation']))
                    <div class="an-card">
                        <div class="an-sec">
                            <i class="bi bi-robot" style="color:#a97bff"></i>
                            <h2>Можно отдать роботу</h2>
                            <span class="an-cnt">{{ count($report['automation']) }}</span>
                        </div>
                        @foreach($report['automation'] as $item)
                            <div class="an-auto">
                                <div class="an-auto-q">
                                    {{ $item['question'] }}
                                    @if($item['times'])<span class="an-times">{{ $item['times'] }}</span>@endif
                                </div>
                                @if($item['answer'])<div class="an-auto-a">{{ $item['answer'] }}</div>@endif
                                @if($item['caution'])
                                    <div class="an-auto-c"><i class="bi bi-exclamation-triangle"></i> {{ $item['caution'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="an-model">
                Разбор сделан моделью {{ $analysis->model }} по {{ $metrics['dialogs'] }}
                {{ trans_choice('диалогу|диалогам|диалогам', $metrics['dialogs']) }} этого дня.
                Цифры считает CRM, модель их не меняет.
            </div>
        @endif
    @endif

    {{-- Разбор идёт одним запросом и занимает до минуты: без этой шторки
         человек смотрит в застывшую страницу и жмёт кнопку второй раз. --}}
    <div class="an-loader" id="anWait" hidden data-estimate="{{ $estimate }}">
        <div class="an-loader-card">
            <div class="an-loader-spin"></div>
            <div class="an-loader-title" id="anWaitTitle">Разбираем день</div>
            <div class="an-loader-sub" id="anWaitSub">
                Claude читает {{ $metrics['dialogs'] }}
                {{ trans_choice('диалог|диалога|диалогов', $metrics['dialogs']) }}
                за {{ $date->locale('ru')->translatedFormat('j F') }}
            </div>
            <div class="an-loader-bar"><div id="anWaitFill"></div></div>
            <div class="an-loader-time" id="anWaitTime"></div>
            <div class="an-loader-note" id="anWaitNote">
                Не закрывайте вкладку — страница обновится сама.
            </div>
            <button type="button" class="an-btn" id="anWaitClose" hidden>Закрыть</button>
        </div>
    </div>
</div>

<style>
.an-wrap{max-width:1560px;margin:0 auto;padding:20px 16px 40px;color:#f4f4f5;
  --card:#16161a;--card2:#1e1e24;--line:#27272a;--wa:#25d366;--t2:#a1a1aa;--t3:#71717a;
  --red:#f87171;--amber:#eab308;--blue:#7fb0ff;--violet:#a97bff;}
.an-wrap *{scrollbar-width:thin;scrollbar-color:#33333c transparent;}
.an-wrap ::-webkit-scrollbar{width:9px;height:9px;}
.an-wrap ::-webkit-scrollbar-track{background:transparent;}
.an-wrap ::-webkit-scrollbar-thumb{background:#33333c;border-radius:20px;
  border:2px solid transparent;background-clip:content-box;}
.an-wrap ::-webkit-scrollbar-thumb:hover{background:#4a4a55;background-clip:content-box;}

/* ── шапка ─────────────────────────────────────────────────────────── */
.an-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.an-back{width:34px;height:34px;border-radius:50%;flex:0 0 auto;text-decoration:none;
  background:var(--card);border:1px solid var(--line);color:var(--t2);
  display:flex;align-items:center;justify-content:center;}
.an-back:hover{color:#fff;border-color:#3f3f46;}
.an-head h1{margin:0;font-size:21px;font-weight:800;}
.an-sub{font-size:12.5px;color:var(--t3);margin-top:2px;}
.an-days{display:flex;gap:6px;margin-left:auto;flex-wrap:wrap;}
.an-day{padding:6px 11px;border-radius:9px;background:var(--card);border:1px solid var(--line);
  color:var(--t2);font-size:12px;text-decoration:none;}
.an-day:hover{color:#fff;border-color:#3f3f46;}
.an-day.on{background:rgba(37,211,102,.16);border-color:rgba(37,211,102,.4);color:var(--wa);font-weight:700;}
.an-picker input{background:var(--card);border:1px solid var(--line);border-radius:9px;
  padding:7px 10px;color:var(--t2);font-size:12.5px;color-scheme:dark;}
.an-btn{display:inline-flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--line);
  border-radius:10px;padding:8px 14px;color:var(--t2);font-size:12.5px;font-weight:600;cursor:pointer;}
.an-btn:hover{color:#fff;border-color:#3f3f46;}
.an-btn.pri{background:var(--wa);border-color:var(--wa);color:#04240f;}
.an-btn.pri:hover{filter:brightness(1.06);color:#04240f;}

.an-flash{display:flex;align-items:center;gap:9px;padding:11px 15px;border-radius:12px;
  font-size:13.5px;margin-bottom:14px;}
.an-flash.bad{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#fca5a5;}
.an-flash.ok{background:rgba(37,211,102,.1);border:1px solid rgba(37,211,102,.3);color:var(--wa);}

.an-card{background:var(--card);border:1px solid var(--line);border-radius:14px;}

/* ── верх: вывод и две цифры ───────────────────────────────────────── */
.an-top{display:grid;grid-template-columns:1.6fr 1fr 1fr;gap:14px;margin-bottom:14px;}
.an-lbl{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;
  letter-spacing:.7px;text-transform:uppercase;color:var(--t3);}
.an-verdict{padding:18px 20px;}
.an-verdict p{margin:11px 0 0;font-size:14px;line-height:1.6;color:#e4e4e7;}
.an-verdict p.an-muted{color:var(--t2);font-size:13.5px;}
.an-big{padding:18px 20px;}
.an-num{font-size:36px;font-weight:800;line-height:1.1;margin:8px 0 6px;font-variant-numeric:tabular-nums;}
.an-num.bad{color:var(--red);}
.an-num.ok{color:var(--wa);}
.an-cap{font-size:12.5px;color:var(--t2);line-height:1.5;}

/* ── шкала дня ─────────────────────────────────────────────────────── */
.an-tl{padding:16px 20px 14px;margin-bottom:14px;}
.an-bars{display:flex;align-items:stretch;gap:6px;height:148px;margin:16px 0 0;}
.an-bar{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:5px;}
.an-bar-val{font-size:10.5px;color:var(--t3);font-variant-numeric:tabular-nums;
  height:14px;line-height:14px;}
/* Дорожка держит высоту, заливка растёт от низа: так столбики стоят на
   одной линии, а подписи не съезжают. */
.an-bar-track{position:relative;flex:1;width:100%;min-height:0;
  border-radius:6px;background:#151519;}
.an-bar-fill{position:absolute;left:0;right:0;bottom:0;min-height:4px;
  border-radius:5px;background:#26262c;transition:filter .15s;}
.an-bar-hour{font-size:10px;color:#52525b;font-variant-numeric:tabular-nums;}
.an-bar.ok .an-bar-fill{background:linear-gradient(180deg,#25d366,#1a9c4c);}
.an-bar.slow .an-bar-fill{background:linear-gradient(180deg,#eab308,#a67c06);}
.an-bar.bad .an-bar-fill{background:linear-gradient(180deg,#f87171,#b53f3f);}
.an-bar.off .an-bar-fill{opacity:.5;}
.an-bar.off .an-bar-hour{color:#3f3f46;}
.an-bar:hover .an-bar-fill{filter:brightness(1.15);}
.an-tl-foot{display:flex;align-items:center;gap:16px;flex-wrap:wrap;
  margin-top:14px;padding-top:12px;border-top:1px solid var(--line);}
.an-legend{display:flex;gap:14px;flex-wrap:wrap;font-size:11.5px;color:var(--t3);}
.an-legend span{display:inline-flex;align-items:center;gap:6px;}
.an-dot{width:9px;height:9px;border-radius:3px;display:inline-block;}
.an-dot.ok{background:#25d366;}
.an-dot.slow{background:#eab308;}
.an-dot.bad{background:#f87171;}
.an-dot.off{background:#26262c;}
.an-meta{margin-left:auto;font-size:11.5px;color:var(--t3);}

/* ── призыв разобрать день ─────────────────────────────────────────── */
.an-cta{display:flex;align-items:center;gap:20px;flex-wrap:wrap;
  background:var(--card);border:1px dashed var(--line);border-radius:14px;padding:20px 22px;}
.an-cta b{display:block;font-size:15px;margin-bottom:4px;}
.an-cta span{font-size:13px;color:var(--t2);}
.an-cta form{margin-left:auto;}

/* ── находки ───────────────────────────────────────────────────────── */
.an-sec{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line);}
.an-sec h2{margin:0;font-size:13px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;}
.an-cnt{margin-left:auto;font-size:12px;font-weight:700;color:var(--t3);
  background:var(--card2);border-radius:20px;padding:2px 10px;}

.an-find{border-bottom:1px solid #202024;}
.an-find:last-child{border-bottom:0;}
.an-find > summary{display:flex;align-items:center;gap:10px;padding:12px 18px;cursor:pointer;
  list-style:none;}
.an-find > summary::-webkit-details-marker{display:none;}
.an-find > summary:hover{background:var(--card2);}
.an-pill{font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
  padding:3px 8px;border-radius:6px;white-space:nowrap;flex:0 0 auto;width:78px;text-align:center;}
.an-pill.lost{background:rgba(248,113,113,.15);color:var(--red);}
.an-pill.slow{background:rgba(234,179,8,.15);color:var(--amber);}
.an-pill.quality{background:rgba(127,176,255,.15);color:var(--blue);}
.an-pill.good{background:rgba(37,211,102,.14);color:var(--wa);}
.an-ava{width:30px;height:30px;border-radius:50%;flex:0 0 auto;background:rgba(37,211,102,.14);
  color:var(--wa);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;}
.an-who{font-size:13.5px;font-weight:700;white-space:nowrap;}
.an-what{flex:1;min-width:0;font-size:13px;color:var(--t2);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.an-wait{font-size:11.5px;font-weight:700;color:var(--amber);white-space:nowrap;
  background:rgba(234,179,8,.13);border-radius:20px;padding:3px 9px;}
.an-open{display:inline-flex;align-items:center;gap:6px;color:var(--wa);font-size:12px;
  font-weight:700;text-decoration:none;white-space:nowrap;padding:4px 8px;border-radius:8px;}
.an-open:hover{background:rgba(37,211,102,.1);color:var(--wa);}
.an-caret{color:var(--t3);font-size:12px;transition:transform .15s;}
.an-caret.hide{visibility:hidden;}
.an-find[open] > summary .an-caret{transform:rotate(180deg);}
.an-body{padding:0 18px 15px 106px;}
.an-body p{margin:0;font-size:13px;line-height:1.55;color:var(--t2);}
.an-quote{margin-top:9px;padding:9px 12px;border-radius:0 8px 8px 0;background:#111114;
  border-left:2px solid var(--line);font-size:12.5px;color:var(--t2);font-style:italic;}
.an-fix{display:flex;gap:8px;margin-top:9px;font-size:12.5px;color:var(--wa);line-height:1.5;}

/* ── что делать ────────────────────────────────────────────────────── */
.an-bottom{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;align-items:start;}
.an-todo{margin:0;padding:12px 18px 16px 38px;}
.an-todo li{font-size:13.5px;line-height:1.55;color:#e4e4e7;padding:7px 0;
  border-bottom:1px solid #202024;}
.an-todo li:last-child{border-bottom:0;}
.an-todo li::marker{color:var(--t3);font-weight:800;font-size:12px;}
.an-auto{padding:14px 18px;border-bottom:1px solid #202024;}
.an-auto:last-child{border-bottom:0;}
.an-auto-q{display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:700;}
.an-times{font-size:11px;font-weight:800;color:var(--t3);background:var(--card2);
  border-radius:20px;padding:2px 9px;white-space:nowrap;}
.an-auto-a{margin-top:9px;padding:10px 12px;border-radius:10px;line-height:1.55;
  background:rgba(37,211,102,.07);border:1px solid rgba(37,211,102,.18);
  font-size:12.5px;color:#d4f7e0;}
.an-auto-c{display:flex;gap:7px;margin-top:8px;font-size:12px;color:var(--t3);line-height:1.5;}

.an-model{margin-top:16px;text-align:center;font-size:11.5px;color:#3f3f46;}

.an-empty{text-align:center;padding:60px 20px;background:var(--card);
  border:1px dashed var(--line);border-radius:14px;color:var(--t3);}
.an-empty i{font-size:30px;display:block;margin-bottom:12px;opacity:.5;}
.an-empty p{margin:0 0 6px;font-size:15px;font-weight:600;color:#f3f3f5;}
.an-empty span{font-size:13px;}

/* ── шторка ожидания ───────────────────────────────────────────────
   Класс свой, не an-wait: так зовётся бейдж ожидания в находке, и
   заливка шторки однажды растеклась на всю страницу поверх контента. */
.an-loader{position:fixed;inset:0;z-index:1200;display:flex;align-items:center;justify-content:center;
  background:rgba(8,8,10,.82);backdrop-filter:blur(3px);padding:20px;}
.an-loader[hidden]{display:none;}
.an-loader-card{width:100%;max-width:420px;text-align:center;padding:30px 28px 26px;
  background:var(--card);border:1px solid var(--line);border-radius:18px;}
.an-loader-spin{width:38px;height:38px;margin:0 auto 16px;border-radius:50%;
  border:3px solid rgba(37,211,102,.18);border-top-color:var(--wa);animation:an-spin .9s linear infinite;}
@keyframes an-spin{to{transform:rotate(360deg);}}
.an-loader-title{font-size:17px;font-weight:800;}
.an-loader-sub{font-size:13px;color:var(--t2);margin-top:6px;line-height:1.5;}
.an-loader-bar{height:6px;border-radius:6px;background:#202024;overflow:hidden;margin:18px 0 10px;}
.an-loader-bar > div{height:100%;width:0;border-radius:6px;
  background:linear-gradient(90deg,#1a9c4c,#25d366);transition:width .5s linear;}
.an-loader-time{font-size:13px;font-weight:700;color:var(--wa);font-variant-numeric:tabular-nums;}
.an-loader-note{font-size:11.5px;color:var(--t3);margin-top:8px;line-height:1.5;}
.an-loader.bad .an-loader-spin{border-color:rgba(248,113,113,.2);border-top-color:var(--red);animation:none;}
.an-loader.bad .an-loader-time{color:var(--red);}
.an-loader.bad .an-loader-bar > div{background:var(--red);}
#anWaitClose{margin-top:16px;}

@media (max-width: 1100px){
  .an-top{grid-template-columns:1fr;}
  .an-bottom{grid-template-columns:1fr;}
  .an-meta{margin-left:0;}
}
@media (max-width: 700px){
  .an-what{display:none;}
  .an-body{padding-left:18px;}
  .an-bar-val{display:none;}
}
</style>

<script>
// Клик по «Чат» внутри свёрнутой находки должен вести в переписку,
// а не складывать карточку.
document.querySelectorAll('.an-find .an-open').forEach(function (link) {
    link.addEventListener('click', function (e) { e.stopPropagation(); });
});

// Разбор — один долгий запрос к модели. Отправляем его сами и держим
// человека в курсе: сколько прошло, сколько обычно занимает, что дальше.
(function () {
    var box = document.getElementById('anWait');
    if (!box) return;

    var fill = document.getElementById('anWaitFill');
    var time = document.getElementById('anWaitTime');
    var note = document.getElementById('anWaitNote');
    var title = document.getElementById('anWaitTitle');
    var close = document.getElementById('anWaitClose');
    var estimate = parseInt(box.dataset.estimate, 10) || 60;
    var timer = null;

    // «85 сек» читается хуже, чем «1 мин 25 сек».
    function human(sec) {
        if (sec < 60) return sec + ' сек';
        var m = Math.floor(sec / 60), s = sec % 60;
        return s ? m + ' мин ' + s + ' сек' : m + ' мин';
    }

    function tick(started) {
        var passed = Math.round((Date.now() - started) / 1000);
        var left = estimate - passed;

        if (left > 0) {
            // До обещанного срока полоса доходит только до 92%: последний
            // процент рисовать нечестно, ответа ещё нет.
            fill.style.width = Math.min(92, Math.round(passed / estimate * 92)) + '%';
            time.textContent = 'осталось около ' + human(left);
        } else {
            fill.style.width = '96%';
            time.textContent = 'идёт дольше обычного — ' + human(passed) + ' из ~' + human(estimate);
            note.textContent = 'Модель ещё думает. Прервать нельзя — дождитесь ответа.';
        }
    }

    function fail(message) {
        clearInterval(timer);
        box.classList.add('bad');
        title.textContent = 'Разбор не получился';
        fill.style.width = '100%';
        time.textContent = message;
        note.textContent = 'Можно попробовать ещё раз — день и переписка на месте.';
        close.hidden = false;
    }

    close.addEventListener('click', function () {
        box.hidden = true;
        box.classList.remove('bad');
        close.hidden = true;
    });

    document.querySelectorAll('form.an-run').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            title.textContent = form.querySelector('[name=force]') ? 'Пересобираем разбор' : 'Разбираем день';
            box.hidden = false;
            var started = Date.now();
            tick(started);
            timer = setInterval(function () { tick(started); }, 1000);

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            }).then(function (res) {
                return res.json().catch(function () { return { ok: res.ok }; });
            }).then(function (data) {
                if (!data || !data.ok) {
                    fail((data && data.error) || 'Модель не ответила');
                    return;
                }

                clearInterval(timer);
                fill.style.width = '100%';
                time.textContent = 'готово' + (data.seconds ? ' за ' + human(data.seconds) : '');
                note.textContent = 'Открываем разбор…';
                location.href = location.href.split('#')[0];
            }).catch(function () {
                fail('Связь с сервером оборвалась');
            });
        });
    });
})();
</script>
@endsection
