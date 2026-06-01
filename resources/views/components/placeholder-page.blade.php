@props([
    'icon' => 'home',
    'title' => 'Page',
    'description' => '',
    'eyebrow' => null,
])
@php
    $phEyebrow = strtoupper($eyebrow ?? $title);
@endphp
<div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
    <div>
        <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
            <x-dynamic-component :component="'feathericon-' . $icon" class="w-[13px] h-[13px]" stroke-width="1.75"/>
            {{ $phEyebrow }}
        </div>
        <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">{{ $title }}</h1>
        <div class="text-[13px] text-fg-3 mt-1.5">{{ $description }}</div>
    </div>
</div>
<div class="flex flex-col items-center justify-center text-center py-[90px] px-5 border border-dashed border-line rounded-surface bg-surface">
    <div class="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4">
        <x-dynamic-component :component="'feathericon-' . $icon" class="w-6 h-6" stroke-width="1.75"/>
    </div>
    <h4 class="font-sans font-semibold text-[22px] text-fg-1 leading-[1.2] tracking-[-0.01em] mb-1.5">{{ $title }} — next in the build queue</h4>
    <p class="text-[14px] text-fg-3 max-w-[420px]">{{ $description }} We'll design this surface next, iterating page by page from the Dashboard.</p>
</div>
