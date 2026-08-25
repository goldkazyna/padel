@extends('layouts.app')

@section('title', 'Дополнительные отчёты')

@section('content')
<div class="container-fluid" style="padding:20px;max-width:1400px;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="{{ route('club.reports.index') }}"
           style="width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;background:#16161a;border:1px solid #27272a;border-radius:10px;color:#a1a1aa;text-decoration:none;">
            <i class="bi bi-arrow-left"></i>
        </a>
        <i class="bi bi-file-earmark-bar-graph" style="font-size:22px;color:#22c47a;"></i>
        <h1 style="font-size:22px;font-weight:800;margin:0;">Дополнительные отчёты</h1>
        <div style="flex:1;"></div>
        <div style="color:#71717a;font-size:13px;">{{ $club->name }} · {{ $periodLabel }}</div>
    </div>

    {{-- Period selector --}}
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:24px;">
        @php
            $presets = ['today' => 'Сегодня', 'week' => 'Неделя', 'month' => 'Месяц', 'prev_month' => 'Прошлый месяц'];
        @endphp
        @foreach ($presets as $key => $label)
            @php $isActive = $preset === $key; @endphp
            <a href="{{ route('club.reports.extra.index', ['preset' => $key]) }}"
               style="padding:8px 14px;border-radius:18px;text-decoration:none;
                      background:{{ $isActive ? 'rgba(34,196,122,0.14)' : 'rgba(255,255,255,0.04)' }};
                      color:{{ $isActive ? '#22c47a' : '#a0a0a0' }};
                      font-weight:600;font-size:13px;">
                {{ $label }}
            </a>
        @endforeach
        <form method="GET" action="{{ route('club.reports.extra.index') }}" style="display:inline-flex;gap:6px;align-items:center;margin-left:8px;">
            <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" style="padding:6px 10px;border-radius:8px;background:#1c1c21;color:#f3f3f5;border:1px solid rgba(255,255,255,0.06);font-size:13px;">
            <span style="color:#6a6a73;">—</span>
            <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" style="padding:6px 10px;border-radius:8px;background:#1c1c21;color:#f3f3f5;border:1px solid rgba(255,255,255,0.06);font-size:13px;">
            <button type="submit" style="padding:8px 14px;border-radius:8px;background:#22c47a;color:#0a0a0d;border:none;font-weight:700;font-size:13px;cursor:pointer;">Применить</button>
        </form>
    </div>

    {{-- Разбор долга по конкретному человеку — отдельный экран, не Excel:
         его показывают клиенту в разговоре и печатают в PDF. --}}
    <a href="{{ route('club.reports.debts.client') }}"
       style="display:flex;align-items:center;gap:12px;padding:16px 18px;border-radius:12px;
              background:linear-gradient(135deg,rgba(240,85,77,.14),rgba(28,28,33,.9));
              border:1px solid rgba(240,85,77,.35);text-decoration:none;color:inherit;margin-bottom:28px;">
        <i class="bi bi-person-lines-fill" style="font-size:20px;color:#f0554d;"></i>
        <div style="flex:1;">
            <div style="font-size:14px;font-weight:700;color:#f3f3f5;">Задолженности по клиенту</div>
            <div style="font-size:12px;color:#a1a1aa;margin-top:2px;">Кто и за какие брони должен — с датами, временем и кортом</div>
        </div>
        <span style="background:rgba(240,85,77,.16);color:#f0554d;padding:3px 10px;border-radius:20px;
                     font-size:11px;font-weight:700;white-space:nowrap;">Экран · PDF</span>
    </a>

    {{-- Report groups --}}
    @foreach ($grouped as $category => $reports)
        <div style="margin-bottom:28px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#6a6a73;margin-bottom:12px;">
                {{ $category }}
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;">
                @foreach ($reports as $r)
                    @php
                        $dlUrl = route('club.reports.extra.download', $r['slug'])
                            . '?' . http_build_query(request()->only(['preset','from','to']));
                    @endphp
                    <a href="{{ $dlUrl }}"
                       style="display:flex;align-items:center;justify-content:space-between;gap:12px;
                              padding:14px 16px;border-radius:12px;
                              background:#1c1c21;border:1px solid rgba(255,255,255,0.06);
                              text-decoration:none;color:inherit;transition:background .15s;"
                       onmouseover="this.style.background='rgba(34,196,122,0.07)'"
                       onmouseout="this.style.background='#1c1c21'">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="bi bi-file-earmark-excel" style="font-size:18px;color:#22c47a;flex-shrink:0;"></i>
                            <span style="font-size:14px;font-weight:600;color:#f3f3f5;">{{ $r['label'] }}</span>
                        </div>
                        <span style="background:rgba(34,196,122,0.12);color:#22c47a;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;flex-shrink:0;">
                            Excel
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach

</div>
@endsection
