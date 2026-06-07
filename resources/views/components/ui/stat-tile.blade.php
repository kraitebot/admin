@props([
    'icon' => null,
    'label' => '',
    'value' => '',
    'sub' => null,
    'delta' => null,
    'spark' => null,
])
@php
    // Build the sparkline path in PHP from a value series (mock-friendly).
    $sparkPath = null;
    if (is_array($spark) && count($spark) >= 2) {
        $w = 100;
        $h = 26;
        $min = min($spark);
        $max = max($spark);
        $range = ($max - $min) ?: 1;
        $points = [];
        foreach (array_values($spark) as $i => $v) {
            $x = ($i / (count($spark) - 1)) * $w;
            $y = $h - (($v - $min) / $range) * $h;
            $points[] = round($x, 2) . ',' . round($y, 2);
        }
        $sparkPath = 'M ' . implode(' L ', $points);
    }
@endphp
<div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast">
    <div class="flex items-center justify-between gap-2">
        <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px]">
            @if($icon)
                <x-dynamic-component :component="'feathericon-' . $icon" class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>
            @endif
            {{ $label }}
        </span>
        @if($delta !== null)
            <span class="font-mono text-[10px] font-bold tabular-nums py-0.5 px-1.5 rounded-chip {{ $delta >= 0 ? 'text-pnlup bg-pnlup-bg' : 'text-pnldown bg-pnldown-bg' }}">{{ ($delta >= 0 ? '+' : '−') . abs($delta) }}%</span>
        @endif
    </div>
    <div class="flex items-end justify-between gap-3">
        <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] text-fg-1 leading-none">{{ $value }}</span>
        {{ $slot }}
    </div>
    @if($sparkPath)
        <div class="h-[26px] -mb-1">
            <svg viewBox="0 0 100 26" preserveAspectRatio="none" class="w-full h-full">
                <path d="{{ $sparkPath }}" fill="none" stroke="var(--pnl-up-fg)" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
            </svg>
        </div>
    @endif
    @if($sub)
        <span class="font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute">{{ $sub }}</span>
    @endif
</div>
