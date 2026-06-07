@props([
    'state' => 'healthy',
    'pulse' => false,
])
@php
    $colors = [
        'healthy' => 'var(--pnl-up-fg)',
        'operational' => 'var(--pnl-up-fg)',
        'degraded' => 'var(--warn)',
        'draining' => 'var(--info)',
        'down' => 'var(--danger)',
        'maintenance' => 'var(--fg-mute)',
    ];
    $color = $colors[$state] ?? $colors['healthy'];
@endphp
<span {{ $attributes->merge(['class' => 'w-[8px] h-[8px] rounded-chip flex-shrink-0' . ($pulse ? ' animate-pulse-soft' : '')]) }}
      style="background: {{ $color }}"></span>
