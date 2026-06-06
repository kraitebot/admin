{{-- Form group — a banded sub-section: filled header strip (icon + title +
     optional mono hint or right slot) over a 2-col field grid.
     Props: title, icon (feather name), hint, cols (2|1). --}}
@props([
    'title',
    'icon' => null,
    'hint' => null,
    'right' => null,
    'cols' => 2,
])
<div class="border-b border-line-soft last:border-b-0">
    <div class="flex items-center justify-between gap-3 py-[13px] px-6 bg-surface-2 border-b border-line-soft max-[640px]:px-4">
        <h4 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
            @if($icon)
                <x-dynamic-component :component="'feathericon-' . $icon" class="w-4 h-4 text-fg-3" stroke-width="1.75"/>
            @endif
            {{ $title }}
        </h4>
        @if($right)
            {{ $right }}
        @elseif($hint)
            <span class="font-mono text-[10.5px] text-fg-mute tracking-[0.02em]">{{ $hint }}</span>
        @endif
    </div>
    <div class="py-5 px-6 max-[640px]:px-4">
        <div class="grid gap-x-5 gap-y-5 {{ $cols === 2 ? 'grid-cols-2 max-[700px]:grid-cols-1' : 'grid-cols-1' }}">
            {{ $slot }}
        </div>
    </div>
</div>
