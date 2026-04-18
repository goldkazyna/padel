@php
    $deltaStr = null;
    $deltaColor = '#6a6a73';
    if ($delta !== null) {
        $isPositive = $delta >= 0;
        $deltaStr = ($isPositive ? '+' : '') . $delta . '%';
        $deltaColor = $isPositive ? '#22c47a' : '#f0554d';
    }
@endphp
<div style="background:#1c1c21;border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:16px;">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
        <div style="width:32px;height:32px;border-radius:8px;background:{{ $iconColor }}22;color:{{ $iconColor }};display:flex;align-items:center;justify-content:center;">
            <i class="bi {{ $icon }}"></i>
        </div>
        <div style="color:#6a6a73;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">{{ $label }}</div>
    </div>
    <div style="font-size:22px;font-weight:800;letter-spacing:-0.3px;">{{ $value }}</div>
    @if ($deltaStr)
        <div style="color:{{ $deltaColor }};font-size:12px;font-weight:600;margin-top:4px;">
            {{ $deltaStr }} к пред. периоду
        </div>
    @else
        <div style="color:#6a6a73;font-size:12px;margin-top:4px;">&nbsp;</div>
    @endif
</div>
