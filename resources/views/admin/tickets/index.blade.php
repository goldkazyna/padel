@extends('layouts.app')

@php
    function hd_status($s) {
        return match ($s) {
            'open' => ['Открыт', '#F59E0B'],
            'answered' => ['Ждём ответа', '#3B82F6'],
            'closed' => ['Закрыт', '#9CA3AF'],
            default => [$s, '#9CA3AF'],
        };
    }
    function hd_initials($name) {
        $name = trim((string) $name);
        if ($name === '') return '—';
        $parts = preg_split('/\s+/', $name);
        $a = mb_substr($parts[0] ?? '', 0, 1);
        $b = mb_substr($parts[1] ?? '', 0, 1);
        return mb_strtoupper($a . $b);
    }
@endphp

@section('content')
<div class="hd-wrap">
    <div class="hd-head">
        <h1>Тикеты поддержки</h1>
    </div>

    <div class="hd-tabs">
        @php $tabs = [
            ['', 'Все', $counts['all']],
            ['open', 'Открытые', $counts['open']],
            ['answered', 'Ждём ответа', $counts['answered']],
            ['closed', 'Закрытые', $counts['closed']],
        ]; @endphp
        @foreach($tabs as [$key, $label, $n])
            <a href="{{ route('admin.tickets.index', array_filter(['status' => $key, 'q' => $q])) }}"
               class="hd-tab {{ (string)$status === (string)$key ? 'active' : '' }}">
                {{ $label }} <span class="hd-tab-n">{{ $n }}</span>
            </a>
        @endforeach
    </div>

    <div class="hd-body">
        {{-- ЛЕВО: список --}}
        <div class="hd-list">
            <form method="GET" action="{{ route('admin.tickets.index') }}" class="hd-search">
                @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                <input type="text" name="q" value="{{ $q }}" placeholder="Поиск по теме или игроку…">
            </form>

            <div class="hd-items">
                @forelse($tickets as $t)
                    @php [$slabel, $scolor] = hd_status($t->status); @endphp
                    <a href="{{ route('admin.tickets.show', array_filter(['ticket' => $t->id, 'status' => $status, 'q' => $q])) }}"
                       class="hd-item {{ $selected && $selected->id === $t->id ? 'sel' : '' }} {{ $t->is_urgent ? 'urgent' : '' }}">
                        <div class="hd-ava">{{ hd_initials($t->user->name ?? '') }}</div>
                        <div class="hd-item-main">
                            <div class="hd-item-subj">{{ $t->subject }}</div>
                            <div class="hd-item-name">{{ $t->user->name ?? '—' }}</div>
                            <span class="hd-badge" style="--c:{{ $scolor }}">● {{ $slabel }}</span>
                        </div>
                        <div class="hd-item-right">
                            <div class="hd-item-time">{{ optional($t->last_message_at)->format('H:i') }}</div>
                            @if($t->player_unread_count > 0)<span class="hd-dot"></span>@endif
                        </div>
                    </a>
                @empty
                    <div class="hd-empty">Тикетов нет</div>
                @endforelse
            </div>
        </div>

        {{-- ПРАВО: переписка --}}
        <div class="hd-detail">
            @if(!$selected)
                <div class="hd-placeholder">Выберите тикет слева</div>
            @else
                @php [$slabel, $scolor] = hd_status($selected->status); @endphp
                <div class="hd-d-head">
                    <div class="hd-d-title">
                        {{ $selected->subject }} <span class="hd-id">#{{ $selected->id }}</span>
                    </div>
                    <span class="hd-badge hd-badge-lg" style="--c:{{ $scolor }}">● {{ $slabel }}</span>
                </div>

                <div class="hd-d-sub">
                    <div class="hd-ava sm">{{ hd_initials($selected->user->name ?? '') }}</div>
                    <span class="hd-d-name">{{ $selected->user->name ?? '—' }}</span>
                    <span class="hd-sep">·</span>

                    {{-- Метка (категория) --}}
                    <form method="POST" action="{{ route('admin.tickets.category', array_filter(['ticket' => $selected->id, 'status' => $status, 'q' => $q])) }}" class="hd-inline">
                        @csrf
                        <select name="category" onchange="this.form.submit()" class="hd-cat-select {{ $selected->category ? 'set' : '' }}">
                            <option value="">+ Метка</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat }}" @selected($selected->category === $cat)>{{ $cat }}</option>
                            @endforeach
                        </select>
                    </form>

                    {{-- Срочный --}}
                    <form method="POST" action="{{ route('admin.tickets.urgent', array_filter(['ticket' => $selected->id, 'status' => $status, 'q' => $q])) }}" class="hd-inline">
                        @csrf
                        <button class="hd-urgent-btn {{ $selected->is_urgent ? 'on' : '' }}">
                            ⚡ {{ $selected->is_urgent ? 'Срочный' : 'Пометить срочным' }}
                        </button>
                    </form>

                    <div class="hd-d-actions">
                        @if($selected->status !== 'closed')
                            <form method="POST" action="{{ route('admin.tickets.close', array_filter(['ticket' => $selected->id, 'status' => $status, 'q' => $q])) }}" class="hd-inline">
                                @csrf<button class="hd-ghost">Закрыть</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.tickets.reopen', array_filter(['ticket' => $selected->id, 'status' => $status, 'q' => $q])) }}" class="hd-inline">
                                @csrf<button class="hd-ghost">Переоткрыть</button>
                            </form>
                        @endif
                    </div>
                </div>

                @if(session('success'))<div class="hd-flash">{{ session('success') }}</div>@endif

                <div class="hd-thread">
                    @foreach($selected->messages as $m)
                        @php $sup = $m->author_type === 'support'; @endphp
                        <div class="hd-msg {{ $sup ? 'right' : 'left' }}">
                            <div class="hd-bubble">
                                <div class="hd-bubble-body">{{ $m->body }}</div>
                                @if($m->attachments->count())
                                    <div class="hd-atts">
                                        @foreach($m->attachments as $a)
                                            <a href="{{ $a->url }}" target="_blank"><img src="{{ $a->url }}" alt=""></a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <div class="hd-meta">
                                {{ $sup ? 'Поддержка' : ($selected->user->name ?? 'Игрок') }}
                                · {{ optional($m->created_at)->format('H:i') }}
                            </div>
                        </div>
                    @endforeach

                    @if($selected->is_urgent)
                        <div class="hd-system">Тикет помечен как «Срочный»</div>
                    @endif
                </div>

                <div class="hd-compose">
                    <form method="POST" action="{{ route('admin.tickets.reply', array_filter(['ticket' => $selected->id, 'status' => $status, 'q' => $q])) }}" class="hd-quick">
                        @csrf
                        <input type="hidden" name="body" value="Спасибо за обращение! Вопрос решён, закрываю тикет.">
                        <input type="hidden" name="close" value="1">
                        <button class="hd-chip">Решено, закрываю</button>
                    </form>

                    <form method="POST" action="{{ route('admin.tickets.reply', array_filter(['ticket' => $selected->id, 'status' => $status, 'q' => $q])) }}" class="hd-reply">
                        @csrf
                        <textarea name="body" rows="2" placeholder="Написать ответ игроку…" required></textarea>
                        <button class="hd-send">➤ Ответить</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>

<style>
.hd-wrap{background:#0F1115;color:#E5E7EB;border-radius:14px;padding:20px;min-height:78vh;}
.hd-head h1{font-size:24px;font-weight:800;margin:0 0 16px;color:#fff;}
.hd-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.hd-tab{padding:7px 14px;border-radius:10px;color:#9CA3AF;text-decoration:none;font-weight:600;font-size:14px;}
.hd-tab.active{background:#1C1F26;color:#fff;}
.hd-tab-n{color:#22C55E;font-weight:800;margin-left:4px;}
.hd-body{display:flex;gap:16px;align-items:stretch;}
.hd-list{width:360px;flex:none;}
.hd-search input{width:100%;background:#1C1F26;border:1px solid #2A2E37;border-radius:10px;padding:10px 12px;color:#E5E7EB;outline:none;}
.hd-items{margin-top:10px;display:flex;flex-direction:column;gap:8px;max-height:62vh;overflow:auto;}
.hd-item{display:flex;gap:10px;padding:12px;border-radius:12px;background:#15181E;text-decoration:none;color:inherit;border:1px solid transparent;}
.hd-item:hover{background:#1A1E26;}
.hd-item.sel{background:#1A1E26;border-color:#2A2E37;}
.hd-item.urgent{border-left:3px solid #EF4444;}
.hd-ava{width:38px;height:38px;flex:none;border-radius:9px;background:#2A2E37;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;}
.hd-ava.sm{width:28px;height:28px;font-size:11px;border-radius:7px;}
.hd-item-main{flex:1;min-width:0;}
.hd-item-subj{font-weight:700;color:#fff;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.hd-item-name{color:#9CA3AF;font-size:13px;margin:1px 0 6px;}
.hd-item-right{display:flex;flex-direction:column;align-items:flex-end;justify-content:space-between;}
.hd-item-time{color:#6B7280;font-size:12px;}
.hd-dot{width:9px;height:9px;border-radius:50%;background:#22C55E;margin-top:6px;}
.hd-badge{display:inline-block;font-size:11px;font-weight:800;color:var(--c);background:color-mix(in srgb,var(--c) 18%,transparent);padding:2px 8px;border-radius:6px;}
.hd-badge-lg{font-size:12px;padding:4px 10px;}
.hd-detail{flex:1;min-width:0;background:#15181E;border-radius:12px;padding:18px;display:flex;flex-direction:column;}
.hd-placeholder{color:#6B7280;text-align:center;padding:60px 0;}
.hd-d-head{display:flex;justify-content:space-between;align-items:center;gap:12px;}
.hd-d-title{font-size:18px;font-weight:800;color:#fff;}
.hd-id{color:#6B7280;font-size:14px;font-weight:600;}
.hd-d-sub{display:flex;align-items:center;gap:8px;margin:12px 0 4px;flex-wrap:wrap;}
.hd-d-name{font-weight:600;color:#E5E7EB;}
.hd-sep{color:#4B5563;}
.hd-inline{display:inline;margin:0;}
.hd-cat-select{background:#241C33;border:1px solid #3B2F5C;color:#A78BFA;border-radius:8px;padding:4px 8px;font-weight:700;font-size:12px;outline:none;cursor:pointer;}
.hd-cat-select.set{color:#C4B5FD;}
.hd-urgent-btn{background:transparent;border:1px solid #7F1D1D;color:#F87171;border-radius:8px;padding:4px 10px;font-weight:700;font-size:12px;cursor:pointer;}
.hd-urgent-btn.on{background:#3B1212;}
.hd-d-actions{margin-left:auto;}
.hd-ghost{background:transparent;border:1px solid #2A2E37;color:#9CA3AF;border-radius:8px;padding:5px 12px;font-weight:600;font-size:13px;cursor:pointer;}
.hd-flash{background:#0E2A18;color:#34D399;border-radius:8px;padding:8px 12px;margin-top:10px;font-size:13px;}
.hd-thread{flex:1;overflow:auto;padding:16px 4px;display:flex;flex-direction:column;gap:10px;min-height:200px;}
.hd-msg{max-width:78%;}
.hd-msg.left{align-self:flex-start;}
.hd-msg.right{align-self:flex-end;}
.hd-bubble{background:#1F242D;padding:10px 14px;border-radius:12px;color:#E5E7EB;}
.hd-msg.right .hd-bubble{background:#0F3D2E;}
.hd-bubble-body{white-space:pre-wrap;}
.hd-atts{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.hd-atts img{width:90px;height:90px;object-fit:cover;border-radius:8px;}
.hd-meta{color:#6B7280;font-size:12px;margin:4px 6px 0;}
.hd-msg.right .hd-meta{text-align:right;}
.hd-system{align-self:center;color:#9CA3AF;font-size:12px;padding:6px 0;}
.hd-compose{border-top:1px solid #2A2E37;padding-top:12px;}
.hd-quick{margin-bottom:10px;}
.hd-chip{background:#1C1F26;border:1px solid #2A2E37;color:#D1D5DB;border-radius:18px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;}
.hd-reply{display:flex;gap:10px;align-items:flex-end;}
.hd-reply textarea{flex:1;background:#1C1F26;border:1px solid #2A2E37;border-radius:12px;padding:12px;color:#E5E7EB;outline:none;resize:vertical;}
.hd-send{background:#22C55E;border:none;color:#06251A;font-weight:800;border-radius:12px;padding:12px 20px;cursor:pointer;white-space:nowrap;}
@media (max-width:900px){.hd-body{flex-direction:column;}.hd-list{width:100%;}}
</style>
@endsection
