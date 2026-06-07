@props([
    'pct' => 0,
    'label' => '',
])
@php
    $color = $pct >= 90 ? 'var(--danger)' : ($pct >= 75 ? 'var(--warn)' : 'var(--pnl-up-fg)');
@endphp
<div class="flex flex-col gap-1 min-w-[64px]">
    <div class="flex items-center justify-between gap-2">
        <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">{{ $label }}</span>
        <span class="font-mono text-[11px] font-semibold tabular-nums" style="color: {{ $pct >= 75 ? $color : 'var(--fg-2)' }}">{{ $pct }}%</span>
    </div>
    <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden">
        <div class="h-full rounded-chip transition-[width] duration-base" style="width: {{ $pct }}%; background: {{ $color }}"></div>
    </div>
</div>
