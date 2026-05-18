@extends('layouts.app')

@section('title', 'Клиенты без телефона')

@section('content')
<div class="container-fluid" style="padding:20px;max-width:1100px;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="{{ route('club.reports.index') }}"
           style="width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;background:#16161a;border:1px solid #27272a;border-radius:10px;color:#a1a1aa;text-decoration:none;">
            <i class="bi bi-arrow-left"></i>
        </a>
        <i class="bi bi-exclamation-triangle-fill" style="font-size:22px;color:#f97316;"></i>
        <h1 style="font-size:22px;font-weight:800;margin:0;">Клиенты без телефона</h1>
        <span style="background:rgba(249,115,22,0.15);color:#f97316;padding:4px 12px;border-radius:20px;font-weight:700;font-size:12px;">
            всего {{ $totalCount }}
        </span>
        <div style="flex:1;"></div>
        <div style="color:#71717a;font-size:13px;">{{ $club->name }}</div>
    </div>

    <div style="background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.3);color:#cbd5e1;padding:12px 16px;border-radius:10px;font-size:13px;line-height:1.5;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;">
        <i class="bi bi-info-circle" style="color:#3b82f6;font-size:16px;flex-shrink:0;margin-top:1px;"></i>
        <span>
            Это клиенты из справочника <strong>«Клиенты»</strong>, у которых не указан телефон.
            Чтобы дозаполнить — нажмите «Открыть карточку» и добавьте номер в разделе «Клиенты».
        </span>
    </div>

    @if($totalCount === 0)
        <div style="background:#16161a;border:1px solid #27272a;border-radius:16px;padding:80px 20px;text-align:center;color:#71717a;">
            <i class="bi bi-check2-circle" style="font-size:56px;color:#22c55e;margin-bottom:16px;display:block;"></i>
            <p style="font-size:16px;margin:0;">У всех клиентов указан телефон — неразобранных нет</p>
        </div>
    @else
        <div style="background:#16161a;border:1px solid #27272a;border-radius:14px;overflow:hidden;margin-bottom:16px;">
            <div style="padding:16px 18px;border-bottom:1px solid #27272a;display:flex;justify-content:space-between;align-items:center;">
                <div style="font-weight:800;font-size:15px;color:#f4f4f5;">Список клиентов</div>
                <div style="color:#71717a;font-size:12px;">
                    {{ $clients->firstItem() }}–{{ $clients->lastItem() }} из {{ $clients->total() }}
                </div>
            </div>
            <div style="display:flex;flex-direction:column;">
                @foreach($clients as $client)
                    @php
                        $nameWords = array_values(array_filter(preg_split('/\s+/', trim($client->name)), fn($w) => mb_strlen($w) > 0));
                        $noLastName = count($nameWords) < 2;
                    @endphp
                    <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;border-top:1px solid #27272a;">
                        <div style="width:42px;height:42px;background:linear-gradient(135deg,#a1a1aa,#52525b);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#0a0a0b;flex-shrink:0;">
                            {{ mb_strtoupper(mb_substr($client->name, 0, 1)) }}
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:15px;font-weight:600;color:#f4f4f5;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <span>{{ $client->name }}</span>
                                @if($noLastName)
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;background:rgba(249,115,22,0.12);color:#f97316;border:1px solid rgba(249,115,22,0.3);border-radius:6px;font-size:11px;font-weight:700;">
                                        <i class="bi bi-exclamation-circle-fill" style="font-size:12px;"></i>
                                        Нет фамилии
                                    </span>
                                @endif
                            </div>
                            <div style="margin-top:4px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <span style="color:#ef4444;font-size:12.5px;font-weight:600;">
                                    <i class="bi bi-telephone-x"></i> Без телефона
                                </span>
                                @if($client->gender)
                                    <span style="color:#71717a;font-size:12px;">{{ $client->gender === 'male' ? 'М' : 'Ж' }}</span>
                                @endif
                                @if($client->birth_date)
                                    <span style="color:#71717a;font-size:12px;">{{ $client->birth_date->format('d.m.Y') }}</span>
                                @endif
                                <span style="color:#71717a;font-size:12px;">добавлен {{ $client->created_at->format('d.m.Y') }}</span>
                            </div>
                            @if($client->note)
                                <div style="margin-top:6px;color:#a1a1aa;font-size:12px;font-style:italic;">{{ \Illuminate\Support\Str::limit($client->note, 120) }}</div>
                            @endif
                        </div>
                        <a href="{{ route('club.clients.index', ['selected' => $client->id]) }}"
                           style="padding:8px 14px;background:#1c1c21;border:1px solid #27272a;border-radius:8px;color:#3b82f6;text-decoration:none;font-size:12.5px;font-weight:700;white-space:nowrap;">
                            Открыть карточку <i class="bi bi-arrow-up-right"></i>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>

        @if($clients->hasPages())
            <div style="display:flex;justify-content:center;">
                {{ $clients->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
