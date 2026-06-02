@php
    /** @var array $p */
    $long = $p['side'] === 'long';
    $status = $p['status'] ?? null;
    $waped = $status === 'waped';
    $rib = $long
        ? ['bg' => '#0e3f2a', 'border' => 'color-mix(in srgb, var(--pnl-up-fg) 30%, transparent)', 'sym' => 'text-[#eafff5]', 'mute' => 'text-[#8fd9b4]', 'chip' => 'text-[#aef0cf]']
        : ['bg' => '#3f1212', 'border' => 'color-mix(in srgb, var(--pnl-down-fg) 32%, transparent)', 'sym' => 'text-[#ffecec]', 'mute' => 'text-[#e0a3a3]', 'chip' => 'text-[#ffc9c9]'];
    $ladder = [26, 44, 62, 80];
    $gainL = min($p['trackPx'], $p['trackTp']);
    $gainW = abs($p['trackTp'] - $p['trackPx']);
    $mc = $long ? 'text-accent' : 'text-pnldown';
@endphp
<div class="ptile bg-surface border-2 rounded-surface overflow-hidden transition-colors duration-fast ease-out {{ $long ? 'ptile--long' : 'ptile--short' }} {{ $waped ? 'ptile--waped' : '' }}">
    <div class="{{ $status === 'opening' ? 'grayscale opacity-[0.62]' : '' }} pt-4 px-[18px] pb-[14px]">
        {{-- Ribbon header --}}
        <div class="flex items-start gap-[11px] -mt-4 -mx-[18px] mb-4 px-[18px] pt-4 pb-3.5 border-b"
             style="background: {{ $rib['bg'] }}; border-color: {{ $rib['border'] }};">
            <div class="w-[30px] h-[30px] rounded-full flex items-center justify-center font-mono font-bold text-[12px] text-white flex-shrink-0 overflow-hidden">
                <img src="https://s2.coinmarketcap.com/static/img/coins/64x64/{{ $p['cmcId'] }}.png" alt="{{ $p['sym'] }}" class="block w-full h-full object-cover"/>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-[7px]">
                    <span class="font-sans font-bold text-[14px] tracking-[-0.01em] flex-shrink-0 {{ $rib['sym'] }}">{{ $p['sym'] }}</span>
                    <span class="text-[12px] whitespace-nowrap overflow-hidden text-ellipsis min-w-0 {{ $rib['mute'] }}">{{ $p['name'] }}</span>
                    @if($status === 'opening')
                        <span class="ml-auto flex-shrink-0 inline-flex items-center gap-[5px] font-mono text-[9px] font-bold tracking-[0.08em] uppercase py-0.5 px-2 rounded-chip whitespace-nowrap bg-surface-3 text-fg-3">
                            <span class="w-1.5 h-1.5 rounded-chip bg-fg-3 animate-pulse-soft"></span>Opening
                        </span>
                    @endif
                    @if($waped)
                        <span class="ml-auto flex-shrink-0 inline-flex items-center gap-[5px] font-mono text-[9px] font-bold tracking-[0.08em] uppercase py-0.5 px-2 rounded-chip whitespace-nowrap text-warn" style="background: color-mix(in srgb, var(--warn) 16%, transparent);">
                            <x-feathericon-layers class="w-[10px] h-[10px]" stroke-width="2"/>WAP'd
                        </span>
                    @endif
                </div>
                <div class="flex items-center gap-[9px] mt-1.5">
                    <span class="inline-flex items-center gap-1 font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase rounded-chip py-1 px-2.5 bg-white/10 {{ $rib['chip'] }}">
                        @if($long)
                            <x-feathericon-arrow-up class="w-[11px] h-[11px]" stroke-width="2"/>
                        @else
                            <x-feathericon-arrow-down class="w-[11px] h-[11px]" stroke-width="2"/>
                        @endif
                        {{ $p['side'] }} {{ $p['lev'] }}
                    </span>
                    <span class="font-mono text-[11px] inline-flex items-center gap-[5px] {{ $rib['mute'] }}">
                        <x-feathericon-clock class="w-[12px] h-[12px]" stroke-width="1.75"/>{{ $p['eta'] }}
                    </span>
                </div>
            </div>
            <div class="flex gap-1 items-center flex-shrink-0 pt-[3px]">
                @foreach($p['osc'] as $d)
                    <i class="block w-1.5 h-1.5 rounded-chip {{ $d === 'up' ? 'bg-pnlup' : ($d === 'down' ? 'bg-pnldown' : 'bg-line-strong') }}"></i>
                @endforeach
            </div>
        </div>

        {{-- Lifecycle track --}}
        <div class="relative mt-[22px] mx-0.5 mb-4 h-[30px]"
             style="--mc: {{ $long ? 'var(--accent)' : 'var(--pnl-down-fg)' }}; --mc-soft: {{ $long ? 'var(--accent-soft)' : 'color-mix(in srgb, var(--pnl-down-fg) 22%, transparent)' }};">
            <div class="absolute left-0 right-0 top-[21px] h-px" style="background: color-mix(in srgb, var(--mc) 45%, transparent);"></div>
            <div class="absolute top-[19px] h-1 rounded-chip" style="left: {{ $gainL }}%; width: {{ $gainW }}%; background: var(--mc);"></div>
            @foreach($ladder as $i => $pos)
                @if($i >= $p['fillN'])
                    <span class="absolute top-[15px] -translate-x-1/2 font-mono text-[10px] bg-surface px-1 leading-[1.4]" style="left: {{ $pos }}%; color: var(--mc);">{{ $i + 1 }}</span>
                @endif
            @endforeach
            <div class="absolute top-0 -translate-x-1/2 flex flex-col items-center z-[2]" style="left: {{ $p['trackTp'] }}%;">
                <span class="font-mono text-[9px] font-bold tracking-[0.06em]" style="color: var(--mc);">TP</span>
                <span class="w-[11px] h-[11px] rotate-45 mt-[3px]" style="background: var(--mc); border-radius: 50% 50% 50% 0; box-shadow: 0 0 0 3px var(--mc-soft);"></span>
            </div>
            <div class="absolute top-0 -translate-x-1/2 flex flex-col items-center z-[2]" style="left: {{ $p['trackPx'] }}%;">
                <span class="font-mono text-[9px] font-bold tracking-[0.06em] text-fg-2">PX</span>
                <span class="w-[10px] h-[10px] rounded-full bg-fg-1 mt-[3px]" style="box-shadow: 0 0 0 3px color-mix(in srgb, var(--fg-1) 20%, transparent);"></span>
            </div>
        </div>

        {{-- Metrics row 1: Path / Limit / Filled --}}
        <div class="grid grid-cols-3 gap-2">
            <div>
                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center gap-[5px] mb-[5px]">
                    <x-feathericon-flag class="w-[11px] h-[11px]" stroke-width="1.75"/>Path
                </div>
                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[14px] {{ $mc }}">{{ number_format($p['path'], 1) }}%</div>
            </div>
            <div class="text-center">
                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center justify-center gap-[5px] mb-[5px]">
                    <x-feathericon-arrow-right class="w-[11px] h-[11px]" stroke-width="1.75"/>Limit
                </div>
                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[14px] text-fg-1">{{ number_format($p['limit'], 1) }}%</div>
            </div>
            <div class="text-right">
                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center justify-end gap-[5px] mb-[5px]">
                    <x-feathericon-check class="w-[11px] h-[11px]" stroke-width="1.75"/>Filled
                </div>
                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[14px] text-fg-1">{{ $p['filled'] }}</div>
            </div>
        </div>

        <div class="h-px bg-line-soft my-[13px]"></div>

        {{-- Metrics row 2: Open / TP / Next --}}
        <div class="grid grid-cols-3 gap-2">
            <div>
                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center gap-[5px] mb-[5px]">
                    <span class="inline-block w-[6px] h-[6px] rounded-full bg-current"></span>Open
                </div>
                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[13px] text-fg-1">{{ $p['open'] }}</div>
            </div>
            <div class="text-center">
                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center justify-center gap-[5px] mb-[5px]">
                    <x-feathericon-arrow-up class="w-[11px] h-[11px]" stroke-width="1.75"/>TP
                </div>
                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[13px] {{ $mc }}">{{ $p['tp'] }}</div>
            </div>
            <div class="text-right">
                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center justify-end gap-[5px] mb-[5px]">
                    <x-feathericon-arrow-down class="w-[11px] h-[11px]" stroke-width="1.75"/>Next
                </div>
                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[13px] text-fg-1">{{ $p['next'] }}</div>
            </div>
        </div>
    </div>
</div>
