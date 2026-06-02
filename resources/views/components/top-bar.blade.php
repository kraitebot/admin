@php
    $iconBtn = 'appearance-none bg-transparent border border-transparent rounded-control text-ink-7 cursor-pointer w-[34px] h-[34px] inline-flex items-center justify-center relative transition-colors duration-fast ease-out hover:text-ink-9 hover:bg-ink-1';
    $userInitials = 'JR';
    $userName = 'J. Renner';
    $userRole = 'TRADER';
@endphp
<header class="h-14 flex-shrink-0 bg-[#07090b] flex items-center gap-4 px-5 z-20 max-[640px]:px-3 max-[640px]:gap-2"
        x-data="{ now: '' , tick() { const d = new Date(); const pad = n => String(n).padStart(2,'0'); this.now = pad(d.getUTCHours())+':'+pad(d.getUTCMinutes())+':'+pad(d.getUTCSeconds()); } }"
        x-init="tick(); setInterval(() => tick(), 1000)">
    <div class="flex items-baseline gap-[9px] whitespace-nowrap">
        <span class="font-sans font-bold text-[15px] tracking-[-0.01em] text-ink-9 max-[640px]:text-[14px]">Kraite</span>
        <span class="text-ink-5 text-[13px]">—</span>
        <span class="font-mono text-[11px] font-medium tracking-[0.06em] uppercase text-green-500 max-[820px]:hidden">Quantum Crypto Bot</span>
    </div>
    <div class="flex-1"></div>
    <div class="font-mono text-[12px] text-ink-7 tabular-nums flex items-center gap-2 max-[820px]:hidden">
        <span class="w-1.5 h-1.5 rounded-chip bg-green-500"></span>
        <span x-text="now + ' UTC'"></span>
    </div>
    <div class="w-px h-6 bg-ink-3"></div>

    <button type="button" class="{{ $iconBtn }}"
            @click="contentDark = !contentDark"
            :title="contentDark ? 'Switch content to light' : 'Switch content to dark'">
        <template x-if="contentDark"><x-feathericon-sun class="w-[18px] h-[18px]" stroke-width="1.75"/></template>
        <template x-if="!contentDark"><x-feathericon-moon class="w-[18px] h-[18px]" stroke-width="1.75"/></template>
    </button>

    <button type="button" class="{{ $iconBtn }}" title="Notifications">
        <x-feathericon-bell class="w-[18px] h-[18px]" stroke-width="1.75"/>
        <span class="absolute top-[5px] right-[5px] w-[7px] h-[7px] rounded-chip bg-danger border-[1.5px] border-[#07090b]"></span>
    </button>

    <div class="w-px h-6 bg-ink-3"></div>

    <button type="button" class="flex items-center gap-[9px] bg-transparent border border-transparent rounded-control py-[5px] pr-2 pl-[6px] cursor-pointer transition-colors duration-fast ease-out hover:bg-ink-1">
        <span class="w-[30px] h-[30px] rounded-full bg-green-50 text-green-600 font-mono font-bold text-[12px] flex items-center justify-center">{{ $userInitials }}</span>
        <span class="flex flex-col leading-[1.15] text-left max-[820px]:hidden">
            <span class="text-[12.5px] font-semibold text-ink-9">{{ $userName }}</span>
            <span class="font-mono text-[10px] text-ink-6 tracking-[0.04em]">{{ $userRole }}</span>
        </span>
        <x-feathericon-chevron-down class="w-[14px] h-[14px] text-ink-6" stroke-width="1.75"/>
    </button>

    <button type="button" class="{{ $iconBtn }}" title="Log out">
        <x-feathericon-log-out class="w-[18px] h-[18px]" stroke-width="1.75"/>
    </button>
</header>
