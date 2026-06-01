@props(['account'])
@php
    $btn = 'appearance-none bg-transparent border border-transparent rounded-chip py-1.5 px-[14px] font-sans text-[12px] font-semibold cursor-pointer whitespace-nowrap transition-colors duration-fast ease-out';
    $exchange = $account['ex'] ?? 'Unknown';
    $tag = $account['tag'] ?? '';
    $note = $account['note'] ?? 'last seen 4m ago';
@endphp
<div x-data="{ open: true }" x-show="open" x-cloak
     class="flex-shrink-0 bg-[#2a0808] text-[#ffb0b0] flex items-center gap-3 py-[13px] px-5 rounded-t-2xl relative z-[1]
            after:content-[''] after:absolute after:inset-x-0 after:top-full after:h-4 after:bg-[#2a0808]
            max-[640px]:flex-wrap max-[640px]:py-3 max-[640px]:px-3">
    <span class="text-danger flex flex-shrink-0 animate-pulse-soft">
        <x-feathericon-wifi-off class="w-[18px] h-[18px]" stroke-width="1.75"/>
    </span>
    <span class="text-[13px] text-[#ffd0d0] max-[640px]:basis-full">
        <strong class="text-white font-bold">{{ $exchange }} ({{ $tag }})</strong> lost connectivity to its exchange —
        <span class="font-mono tabular-nums text-[#ff8585]">{{ $note }}</span>.
        Bot management paused for this account. Open positions are held, not adjusted.
    </span>
    <span class="flex-1 max-[640px]:hidden"></span>
    <button type="button" class="{{ $btn }} border-[#7a1515] text-[#ffc4c4] hover:bg-[#3a0a0a] hover:text-white">View account</button>
    <button type="button" class="{{ $btn }} bg-danger text-white hover:bg-[#ff4d4d]">Retry connection</button>
    <button type="button" @click="open = false" class="{{ $btn }} text-[#c98585] px-2.5 hover:text-white hover:bg-[#3a0a0a]">Dismiss</button>
</div>
