@props(['state' => 'healthy'])
@php
    $map = [
        'healthy' => ['var(--pnl-up-fg)', 'Healthy'],
        'operational' => ['var(--pnl-up-fg)', 'Operational'],
        'degraded' => ['var(--warn)', 'Degraded'],
        'draining' => ['var(--info)', 'Draining'],
        'down' => ['var(--danger)', 'Down'],
        'maintenance' => ['var(--fg-mute)', 'Maintenance'],
    ];
    [$color, $label] = $map[$state] ?? $map['healthy'];
    $pulse = in_array($state, ['down', 'degraded'], true);
@endphp
<span class="inline-flex items-center gap-[7px] py-[5px] px-[11px] rounded-chip border font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase whitespace-nowrap"
      style="color: {{ $color }}; border-color: color-mix(in srgb, {{ $color }} 36%, transparent); background: color-mix(in srgb, {{ $color }} 12%, transparent)">
    <span class="w-[7px] h-[7px] rounded-chip {{ $pulse ? 'animate-pulse-soft' : '' }}" style="background: {{ $color }}"></span>{{ $label }}
</span>
