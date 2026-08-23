{{-- Детальная карточка клиента: всё, что клуб о нём знает, на одной странице.

     Боковая панель в списке умышленно короткая — здесь наоборот, полная
     картина: карты, сертификаты, группы, брони, турниры и деньги. --}}
@extends('layouts.app')
@section('title', $client->name)

@section('content')
@php
    $hours = round($stats['hours'], 1);
    $hoursLabel = rtrim(rtrim(number_format($hours, 1, ',', ' '), '0'), ',');
    $activeCards = $cards->filter(fn($c) => $c->isActual())->count();
    $activeGroups = $groups->filter(fn($g) => $g->status !== 'inactive')->count();
    $certsLeft = ($client->certificates_count ?? 0) - ($client->certificates_used_count ?? 0);
@endphp

<div class="cd-wrap">

    <div class="cd-top">
        <a href="{{ route('club.clients.index', ['selected' => $client->id]) }}" class="cd-back" title="К списку клиентов">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div class="cd-top-actions">
            <a href="{{ route('club.clients.index', ['selected' => $client->id, 'edit' => 1]) }}" class="cd-btn">
                <i class="bi bi-pencil"></i> Редактировать
            </a>
            <a href="{{ route('club.clients.bookings', $client) }}" class="cd-btn">
                <i class="bi bi-calendar-week"></i> Все брони
            </a>
        </div>
    </div>

    {{-- Шапка --}}
    <div class="cd-head">
        <div class="cd-ava">
            @if($appUser?->avatar)
                <img src="{{ $appUser->avatar }}" alt="{{ $client->name }}">
            @else
                {{ mb_strtoupper(mb_substr($client->name, 0, 1)) }}
            @endif
        </div>
        <div class="cd-head-main">
            <h1>{{ $client->name }}</h1>
            <div class="cd-head-phone">@phoneFmt($client->phone)</div>
            <div class="cd-chips">
                @if($appUser)
                    <span class="cd-chip green"><i class="bi bi-phone"></i> В приложении</span>
                @else
                    <span class="cd-chip"><i class="bi bi-phone-slash"></i> Без приложения</span>
                @endif
                @if($client->user_id)
                    <span class="cd-chip"><i class="bi bi-link-45deg"></i> Аккаунт привязан</span>
                @endif
                @if($waiver)
                    <a class="cd-chip green" href="{{ route('club.waivers.show', $waiver->id) }}" target="_blank">
                        <i class="bi bi-file-earmark-check"></i> Отказ подписан
                    </a>
                @endif
                @if($client->card_number)
                    <span class="cd-chip"><i class="bi bi-credit-card-2-front"></i> {{ $client->card_number }}</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Сводка --}}
    <div class="cd-tiles">
        <div class="cd-tile">
            <span class="cd-tile-n">{{ $stats['count'] }}</span>
            <span class="cd-tile-l">броней</span>
        </div>
        <div class="cd-tile">
            <span class="cd-tile-n">{{ $hoursLabel }}</span>
            <span class="cd-tile-l">часов на корте</span>
        </div>
        <div class="cd-tile">
            <span class="cd-tile-n">{{ number_format($stats['amount'], 0, '', ' ') }} ₸</span>
            <span class="cd-tile-l">на бронях</span>
        </div>
        @if($stats['unpaid'] > 0)
            <div class="cd-tile amber">
                <span class="cd-tile-n">{{ $stats['unpaid'] }}</span>
                <span class="cd-tile-l">не оплачено</span>
            </div>
        @endif
        <div class="cd-tile">
            <span class="cd-tile-n">{{ $activeCards }}<span class="cd-tile-of">/{{ $cards->count() }}</span></span>
            <span class="cd-tile-l">карт активно</span>
        </div>
        <div class="cd-tile">
            <span class="cd-tile-n">{{ $certsLeft }}<span class="cd-tile-of">/{{ $client->certificates_count ?? 0 }}</span></span>
            <span class="cd-tile-l">сертификатов</span>
        </div>
        @if($activeGroups > 0)
            <div class="cd-tile">
                <span class="cd-tile-n">{{ $activeGroups }}</span>
                <span class="cd-tile-l">групп</span>
            </div>
        @endif
        @if($tournaments->count())
            <div class="cd-tile">
                <span class="cd-tile-n">{{ $tournaments->count() }}</span>
                <span class="cd-tile-l">турниров</span>
            </div>
        @endif
    </div>

    <div class="cd-cols">
        <div class="cd-col">

            {{-- Клубные карты --}}
            <section class="cd-sec">
                <div class="cd-sec-head">
                    <h2>Клубные карты</h2>
                    <span class="cd-sec-n">{{ $cards->count() }}</span>
                </div>
                @forelse($cards as $card)
                    @php
                        $actual = $card->isActual();
                    @endphp
                    <a class="cd-row {{ $actual ? '' : 'dead' }}" href="{{ route('club.cards.history', $card) }}">
                        <div class="cd-row-main">
                            <div class="cd-row-t">{{ $card->type?->name ?? 'Карта' }}</div>
                            <div class="cd-row-s">
                                <span class="cd-mono">{{ $card->code }}</span>
                                @if($card->expires_at)
                                    · до {{ $card->expires_at->format('d.m.Y') }}
                                @else
                                    · бессрочно
                                @endif
                                @unless($actual) · <span class="cd-red">не активна</span> @endunless
                            </div>
                            @if($card->type?->description)
                                <div class="cd-row-note">{{ $card->type->description }}</div>
                            @endif
                        </div>
                        <div class="cd-row-right">
                            @if($card->isCounter())
                                <span class="cd-bal">{{ (int) $card->balance }}<span class="cd-of">/{{ (int) $card->initial_balance }} ч</span></span>
                            @else
                                <span class="cd-bal cd-disc">−{{ $card->type?->discount_percent }}%</span>
                            @endif
                        </div>
                    </a>
                @empty
                    <div class="cd-empty">Карт нет</div>
                @endforelse
            </section>

            {{-- Брони --}}
            <section class="cd-sec">
                <div class="cd-sec-head">
                    <h2>Ближайшие брони</h2>
                    <span class="cd-sec-n">{{ $upcoming->count() }}</span>
                </div>
                @forelse($upcoming as $b)
                    @include('club.clients._detail_booking', ['b' => $b])
                @empty
                    <div class="cd-empty">Предстоящих броней нет</div>
                @endforelse
            </section>

            <section class="cd-sec">
                <div class="cd-sec-head">
                    <h2>История броней</h2>
                    <span class="cd-sec-n">{{ $past->count() }}</span>
                </div>
                @forelse($past->take(30) as $b)
                    @include('club.clients._detail_booking', ['b' => $b])
                @empty
                    <div class="cd-empty">Броней ещё не было</div>
                @endforelse
                @if($past->count() > 30)
                    <a class="cd-more" href="{{ route('club.clients.bookings', ['client' => $client, 'period' => 'all']) }}">
                        Показать все {{ $past->count() }} <i class="bi bi-chevron-right"></i>
                    </a>
                @endif
            </section>

        </div>

        <div class="cd-col">

            {{-- Информация --}}
            <section class="cd-sec">
                <div class="cd-sec-head"><h2>Информация</h2></div>
                <div class="cd-field">
                    <span>Пол</span>
                    <b>@if($client->gender === 'male') Мужской @elseif($client->gender === 'female') Женский @else — @endif</b>
                </div>
                <div class="cd-field">
                    <span>Дата рождения</span>
                    <b>{{ $client->birth_date ? $client->birth_date->format('d.m.Y') : '—' }}</b>
                </div>
                <div class="cd-field">
                    <span>E-mail</span>
                    <b>@if($client->email)<a href="mailto:{{ $client->email }}">{{ $client->email }}</a>@else — @endif</b>
                </div>
                <div class="cd-field">
                    <span>Добавлен</span>
                    <b>{{ $client->created_at->format('d.m.Y') }}</b>
                </div>
                @if($appUser)
                    <div class="cd-field">
                        <span>Имя в приложении</span>
                        <b>{{ $appUser->name }}</b>
                    </div>
                @endif
            </section>

            {{-- Сертификаты --}}
            <section class="cd-sec">
                <div class="cd-sec-head">
                    <h2>Сертификаты</h2>
                    <a class="cd-sec-link" href="{{ route('club.certificates.client', $client) }}">все <i class="bi bi-chevron-right"></i></a>
                </div>
                @forelse($certificates as $cert)
                    @php
                        $value = match ($cert->value_type) {
                            'amount' => number_format((int) $cert->amount, 0, '', ' ') . ' ₸',
                            'hours' => (int) $cert->hours . ' ч',
                            'tournament' => (int) $cert->tournaments . ' турн.',
                            default => '—',
                        };
                    @endphp
                    <div class="cd-row {{ $cert->used_at ? 'dead' : '' }}">
                        <div class="cd-row-main">
                            <div class="cd-row-t">{{ $cert->title ?: 'Сертификат' }}</div>
                            <div class="cd-row-s">
                                <span class="cd-mono">{{ $cert->number }}</span>
                                @if($cert->used_at) · использован {{ $cert->used_at->format('d.m.Y') }} @endif
                            </div>
                        </div>
                        <div class="cd-row-right"><span class="cd-bal">{{ $value }}</span></div>
                    </div>
                @empty
                    <div class="cd-empty">Сертификатов нет</div>
                @endforelse
            </section>

            {{-- Группы --}}
            @if($groups->count())
            <section class="cd-sec">
                <div class="cd-sec-head">
                    <h2>Группы</h2>
                    <span class="cd-sec-n">{{ $groups->count() }}</span>
                </div>
                @foreach($groups as $gm)
                    <a class="cd-row {{ $gm->status === 'inactive' ? 'dead' : '' }}" href="{{ route('club.groups.show', $gm->group) }}">
                        <div class="cd-row-main">
                            <div class="cd-row-t">{{ $gm->group->name ?? 'Группа' }}</div>
                            <div class="cd-row-s">
                                @if($gm->status === 'inactive') отчислен @else в группе @endif
                                @if($gm->subscription_ends_at) · абонемент до {{ $gm->subscription_ends_at->format('d.m.Y') }} @endif
                            </div>
                        </div>
                        <div class="cd-row-right"><span class="cd-bal">{{ $gm->remaining }}</span></div>
                    </a>
                @endforeach
            </section>
            @endif

            {{-- Пробные занятия --}}
            @if($trials->count())
            <section class="cd-sec">
                <div class="cd-sec-head">
                    <h2>Пробные занятия</h2>
                    <span class="cd-sec-n">{{ $trials->count() }}</span>
                </div>
                @foreach($trials as $tr)
                    <div class="cd-row">
                        <div class="cd-row-main">
                            <div class="cd-row-t">{{ optional(optional($tr->session)->group)->name ?? 'Занятие' }}</div>
                            <div class="cd-row-s">{{ optional($tr->session)->date ? $tr->session->date->format('d.m.Y') : '—' }}</div>
                        </div>
                        <div class="cd-row-right">
                            <span class="cd-bal">{{ (int) $tr->trial_amount > 0 ? number_format($tr->trial_amount, 0, '', ' ') . ' ₸' : 'бесплатно' }}</span>
                        </div>
                    </div>
                @endforeach
            </section>
            @endif

            {{-- Турниры --}}
            @if($tournaments->count())
            <section class="cd-sec">
                <div class="cd-sec-head">
                    <h2>Турниры клуба</h2>
                    <span class="cd-sec-n">{{ $tournaments->count() }}</span>
                </div>
                @foreach($tournaments as $t)
                    <a class="cd-row" href="{{ route('club.tournaments.show', $t) }}">
                        <div class="cd-row-main">
                            <div class="cd-row-t">{{ $t->name }}</div>
                            <div class="cd-row-s">{{ $t->start_date ? $t->start_date->format('d.m.Y') : '—' }}</div>
                        </div>
                    </a>
                @endforeach
            </section>
            @endif

            {{-- Заметка --}}
            @if($client->note)
            <section class="cd-sec">
                <div class="cd-sec-head"><h2>Заметка</h2></div>
                <div class="cd-note">{{ $client->note }}</div>
            </section>
            @endif

        </div>
    </div>
</div>

<style>
.cd-wrap{max-width:1200px;margin:0 auto;padding:20px 16px 48px;color:#f4f4f5;
  --cd-card:#16161a;--cd-card2:#1e1e24;--cd-border:#27272a;--cd-accent:#22c55e;
  --cd-text2:#a1a1aa;--cd-text3:#71717a;--cd-amber:#eab34e;--cd-red:#f0554d;}
.cd-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;flex-wrap:wrap;}
.cd-back{width:40px;height:40px;border-radius:10px;background:var(--cd-card);border:1px solid var(--cd-border);
  display:flex;align-items:center;justify-content:center;color:#f4f4f5;text-decoration:none;font-size:18px;}
.cd-back:hover{background:var(--cd-card2);color:#fff;}
.cd-top-actions{display:flex;gap:8px;flex-wrap:wrap;}
.cd-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:10px;background:var(--cd-card);
  border:1px solid var(--cd-border);color:#d4d4d8;text-decoration:none;font-size:14px;font-weight:600;}
.cd-btn:hover{background:var(--cd-card2);color:#fff;}

.cd-head{display:flex;align-items:center;gap:18px;background:var(--cd-card);border:1px solid var(--cd-border);
  border-radius:16px;padding:20px 22px;margin-bottom:14px;flex-wrap:wrap;}
.cd-ava{width:72px;height:72px;border-radius:18px;background:linear-gradient(135deg,#22c55e,#15803d);
  display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#0a0a0b;
  flex-shrink:0;overflow:hidden;}
.cd-ava img{width:100%;height:100%;object-fit:cover;}
.cd-head-main{min-width:0;}
.cd-head-main h1{font-size:26px;font-weight:800;margin:0 0 4px;}
.cd-head-phone{color:var(--cd-text2);font-size:15px;margin-bottom:10px;}
.cd-chips{display:flex;gap:7px;flex-wrap:wrap;}
.cd-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:8px;background:var(--cd-card2);
  border:1px solid var(--cd-border);color:var(--cd-text2);font-size:12.5px;font-weight:600;text-decoration:none;}
.cd-chip.green{color:var(--cd-accent);border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.1);}
a.cd-chip:hover{filter:brightness(1.15);}

.cd-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:18px;}
.cd-tile{background:var(--cd-card);border:1px solid var(--cd-border);border-radius:12px;padding:14px 16px;}
.cd-tile.amber{border-color:rgba(234,179,78,.4);}
.cd-tile-n{display:block;font-size:22px;font-weight:800;color:#fff;line-height:1.15;}
.cd-tile.amber .cd-tile-n{color:var(--cd-amber);}
.cd-tile-of{font-size:14px;font-weight:700;color:var(--cd-text3);}
.cd-tile-l{display:block;color:var(--cd-text2);font-size:12.5px;margin-top:3px;}

.cd-cols{display:grid;grid-template-columns:1.15fr 1fr;gap:14px;align-items:start;}
@media (max-width:900px){.cd-cols{grid-template-columns:1fr;}}
.cd-col{display:flex;flex-direction:column;gap:14px;min-width:0;}

.cd-sec{background:var(--cd-card);border:1px solid var(--cd-border);border-radius:14px;padding:16px 18px;}
.cd-sec-head{display:flex;align-items:center;gap:9px;margin-bottom:12px;}
.cd-sec-head h2{font-size:13px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--cd-text2);margin:0;}
.cd-sec-n{font-size:12px;font-weight:800;color:var(--cd-text3);background:var(--cd-card2);border-radius:6px;padding:2px 8px;}
.cd-sec-link{margin-left:auto;color:var(--cd-text2);text-decoration:none;font-size:12.5px;font-weight:600;}
.cd-sec-link:hover{color:#fff;}

.cd-row{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:10px;background:var(--cd-card2);
  margin-bottom:7px;text-decoration:none;color:inherit;}
a.cd-row:hover{background:#26262d;}
.cd-row.dead{opacity:.5;}
.cd-row-main{flex:1;min-width:0;}
.cd-row-t{font-size:14.5px;font-weight:700;color:#fff;}
.cd-row-s{font-size:12.5px;color:var(--cd-text2);margin-top:2px;}
.cd-row-note{font-size:12px;color:var(--cd-text3);margin-top:5px;line-height:1.4;white-space:pre-line;}
.cd-row-right{flex-shrink:0;text-align:right;}
.cd-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.4px;}
.cd-red{color:var(--cd-red);}
.cd-bal{font-size:15px;font-weight:800;color:var(--cd-accent);}
.cd-of{font-size:12px;font-weight:700;color:var(--cd-text3);}
.cd-disc{color:var(--cd-amber);}
.cd-paid{display:inline-block;font-size:11.5px;font-weight:800;padding:3px 8px;border-radius:7px;margin-top:4px;}
.cd-paid.no{background:rgba(240,85,77,.14);color:var(--cd-red);}
.cd-empty{color:var(--cd-text3);font-size:13.5px;padding:6px 2px 4px;}
.cd-more{display:inline-flex;align-items:center;gap:5px;color:var(--cd-text2);text-decoration:none;font-size:13px;
  font-weight:600;margin-top:4px;}
.cd-more:hover{color:#fff;}
.cd-field{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 2px;
  border-bottom:1px solid var(--cd-border);}
.cd-field:last-child{border-bottom:none;}
.cd-field span{color:var(--cd-text2);font-size:13px;}
.cd-field b{font-size:13.5px;font-weight:700;}
.cd-field a{color:var(--cd-accent);text-decoration:none;}
.cd-note{color:#d4d4d8;font-size:13.5px;line-height:1.5;white-space:pre-line;}
</style>
@endsection
