{{-- Кто написал и всё ещё ждёт ответа. Экран дежурного, а не аналитика. --}}
@extends('layouts.app')

@section('title', 'WhatsApp — ждут ответа')

@section('content')
@php
    $tz = config('app.schedule_timezone', 'Asia/Almaty');
    $longest = $waiting->first();
@endphp
<div class="wa-wrap">

    <div class="wa-head">
        <a href="{{ route('club.whatsapp.index') }}" class="wa-back"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1>Ждут ответа</h1>
            <div class="wa-sub">
                {{ $club->name }} · рабочее время {{ $workFrom }}–{{ $workTo }},
                порог {{ $threshold }} мин
            </div>
        </div>
    </div>

    <div class="wa-tabs">
        <a href="{{ route('club.whatsapp.waiting') }}" class="{{ $all ? '' : 'on' }}">Последние 3 дня</a>
        <a href="{{ route('club.whatsapp.waiting', ['all' => 1]) }}" class="{{ $all ? 'on' : '' }}">Все без ответа · {{ $totalWaiting }}</a>
    </div>

    <div class="wa-stats">
        <div class="wa-stat">
            <b>{{ $waiting->count() }}</b>
            <span>{{ trans_choice('диалог|диалога|диалогов', $waiting->count()) }} без ответа{{ $all ? '' : ' за 3 дня' }}</span>
        </div>
        <div class="wa-stat {{ $overdue ? 'bad' : '' }}">
            <b>{{ $overdue }}</b>
            <span>просрочено дольше {{ $threshold }} мин</span>
        </div>
        <div class="wa-stat">
            <b>{{ $longest ? \App\Support\WhatsappSla::humanMinutes($longest['waited']) : '—' }}</b>
            <span>самое долгое ожидание</span>
        </div>
    </div>

    @if($waiting->isEmpty())
        <div class="wa-empty">
            <i class="bi bi-check2-circle"></i>
            <p>Все ответили</p>
            <span>{{ $all ? 'Ни одного диалога, где последнее слово осталось за клиентом.' : 'За последние три дня без ответа никого не осталось.' }}</span>
        </div>
    @else
        <div class="wa-list">
            @foreach($waiting as $row)
                @php
                    $client = $clients[substr($row['phone'], -10)] ?? null;
                    $since = $row['since']->timezone($tz);
                @endphp
                <a href="{{ route('club.whatsapp.show', $row['phone']) }}"
                   class="wa-item {{ $row['overdue'] ? 'overdue' : '' }}">
                    <div class="wa-clock {{ $row['overdue'] ? 'bad' : 'ok' }}">
                        {{ \App\Support\WhatsappSla::humanMinutes($row['waited']) }}
                    </div>
                    <div class="wa-item-main">
                        <div class="wa-item-top">
                            <span class="wa-name">{{ $client->name ?? $row['name'] ?? 'Без имени' }}</span>
                            @if(!$row['ever_answered'])
                                <span class="wa-tag new">ни разу не ответили</span>
                            @elseif($client)
                                <span class="wa-tag">клиент</span>
                            @endif
                            @if($row['messages'] > 1)
                                <span class="wa-tag pile">{{ $row['messages'] }} сообщения подряд</span>
                            @endif
                            <span class="wa-time">написал {{ $since->locale('ru')->translatedFormat('j M, H:i') }}</span>
                        </div>
                        <div class="wa-item-bottom">
                            <span class="wa-preview">{{ \Illuminate\Support\Str::limit($row['last']->body ?: $row['last']->preview(), 110) }}</span>
                            <span class="wa-phone">
                                @if($row['hidden_number'])
                                    номер скрыт настройками WhatsApp
                                @else
                                    @phoneFmt($row['phone'])
                                @endif
                            </span>
                        </div>
                    </div>
                    <i class="bi bi-chevron-right wa-go"></i>
                </a>
            @endforeach
        </div>

        <div class="wa-note">
            <i class="bi bi-info-circle"></i>
            Ожидание считается только в рабочие часы: написали ночью — отсчёт пойдёт с открытия.
            Групповые чаты и служебные события WhatsApp сюда не попадают.
        </div>
    @endif
</div>

<style>
.wa-wrap{max-width:900px;margin:0 auto;padding:20px 16px 40px;color:#f4f4f5;
  --card:#16161a;--card2:#1e1e24;--line:#27272a;--wa:#25d366;--bad:#f87171;--t2:#a1a1aa;--t3:#71717a;}
.wa-head{display:flex;align-items:center;gap:14px;margin-bottom:18px;}
.wa-head h1{font-size:22px;font-weight:800;margin:0;}
.wa-back{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;
  background:var(--card);border:1px solid var(--line);border-radius:10px;color:var(--t2);text-decoration:none;}
.wa-sub{color:var(--t3);font-size:13px;margin-top:2px;}

.wa-tabs{display:flex;gap:8px;margin-bottom:14px;}
.wa-tabs a{padding:8px 14px;border-radius:10px;text-decoration:none;font-size:13px;
  background:var(--card);border:1px solid var(--line);color:var(--t2);}
.wa-tabs a.on{background:rgba(37,211,102,.14);border-color:rgba(37,211,102,.35);color:var(--wa);font-weight:700;}
.wa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:16px;}
.wa-stat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;}
.wa-stat b{display:block;font-size:24px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums;}
.wa-stat span{font-size:12px;color:var(--t3);}
.wa-stat.bad b{color:var(--bad);}

.wa-list{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:8px;}
.wa-item{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:10px;
  text-decoration:none;color:inherit;}
.wa-item:hover{background:var(--card2);}
.wa-item + .wa-item{border-top:1px solid var(--line);}
.wa-clock{flex:0 0 auto;min-width:92px;text-align:center;padding:8px 10px;border-radius:10px;
  font-size:13px;font-weight:800;font-variant-numeric:tabular-nums;}
.wa-clock.ok{background:rgba(37,211,102,.14);color:var(--wa);}
.wa-clock.bad{background:rgba(248,113,113,.14);color:var(--bad);}
.wa-item-main{flex:1;min-width:0;}
.wa-item-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.wa-name{font-size:14.5px;font-weight:700;color:#fff;}
.wa-tag{font-size:9.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
  padding:2px 7px;border-radius:6px;background:rgba(37,211,102,.14);color:var(--wa);}
.wa-tag.new{background:rgba(248,113,113,.14);color:var(--bad);}
.wa-tag.pile{background:var(--card2);color:var(--t2);}
.wa-time{margin-left:auto;font-size:11.5px;color:var(--t3);white-space:nowrap;}
.wa-item-bottom{display:flex;align-items:baseline;gap:10px;margin-top:3px;}
.wa-preview{flex:1;min-width:0;font-size:13px;color:var(--t2);overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap;}
.wa-phone{font-size:11.5px;color:var(--t3);white-space:nowrap;font-variant-numeric:tabular-nums;}
.wa-go{color:var(--t3);font-size:14px;}

.wa-empty{text-align:center;padding:60px 20px;background:var(--card);
  border:1px dashed var(--line);border-radius:14px;color:var(--t3);}
.wa-empty i{font-size:30px;display:block;margin-bottom:12px;color:var(--wa);opacity:.7;}
.wa-empty p{margin:0 0 6px;font-size:15px;font-weight:600;color:#f3f3f5;}
.wa-empty span{font-size:13px;}
.wa-note{display:flex;align-items:center;gap:8px;margin-top:14px;color:var(--t3);font-size:12.5px;}
</style>

<script>
// Экран дежурного: обновляем сам, иначе он врёт уже через пять минут.
setTimeout(() => location.reload(), 60000);
</script>
@endsection
