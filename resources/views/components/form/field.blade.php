{{-- Form field shell — label row (+ optional aside slot) over a control,
     with help/error text below. The system-wide field wrapper.
     Props: label, for, help (string|null), error (string|null),
     dir ('long'|'short'|null — tints the label directionally). --}}
@props([
    'label',
    'for' => null,
    'help' => null,
    'error' => null,
    'dir' => null,
    'aside' => null,
])
<div class="flex flex-col">
    <div class="flex items-center justify-between gap-2 mb-[7px]">
        <label @if($for) for="{{ $for }}" @endif
               class="font-mono text-[10px] font-semibold tracking-[0.11em] uppercase {{ $dir === 'long' ? 'text-pnlup' : ($dir === 'short' ? 'text-pnldown' : 'text-fg-mute') }}">{{ $label }}</label>
        @if($dir)
            <span class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase {{ $dir === 'long' ? 'text-pnlup' : 'text-pnldown' }}">{{ $dir === 'long' ? 'Long' : 'Short' }}</span>
        @elseif($aside)
            {{ $aside }}
        @endif
    </div>
    {{ $slot }}
    @if($error)
        <div class="text-[11.5px] leading-[1.45] text-danger mt-1.5 flex items-center gap-1.5">
            <x-feathericon-alert-triangle class="w-3 h-3" stroke-width="1.75"/>{{ $error }}
        </div>
    @elseif($help)
        <div class="text-[11.5px] leading-[1.45] text-fg-mute mt-1.5">{!! $help !!}</div>
    @endif
</div>
