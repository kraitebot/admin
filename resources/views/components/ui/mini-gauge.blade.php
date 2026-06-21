@props([
    'value' => 0,
    'size' => 60,
])
@php
    // Circular perf dial with the value centered in the ring.
    $v = max(0.0, min(100.0, (float) $value));
    $stroke = 6;
    $radius = ($size - $stroke) / 2;
    $center = $size / 2;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - $v / 100);
    // Perf bands: >=80 green (good), 60–80 warn, <60 red — trading-safe semantics.
    $color = $v >= 80 ? 'var(--pnl-up-fg)' : ($v >= 60 ? 'var(--warn)' : 'var(--pnl-down-fg)');
@endphp
<div {{ $attributes->merge(['class' => 'relative inline-flex items-center justify-center flex-shrink-0']) }}
     style="width: {{ $size }}px; height: {{ $size }}px">
    <svg width="{{ $size }}" height="{{ $size }}" class="-rotate-90">
        <circle cx="{{ $center }}" cy="{{ $center }}" r="{{ $radius }}" fill="none" stroke="var(--border)" stroke-width="{{ $stroke }}"/>
        <circle cx="{{ $center }}" cy="{{ $center }}" r="{{ $radius }}" fill="none" stroke="{{ $color }}" stroke-width="{{ $stroke }}" stroke-linecap="round"
                stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $offset }}"
                style="transition: stroke-dashoffset .6s cubic-bezier(.22, 1, .36, 1)"/>
    </svg>
    <span class="absolute font-mono font-bold tabular-nums leading-none" style="font-size: 15px; color: {{ $color }}">{{ round($v) }}<span style="font-size: 9px">%</span></span>
</div>
