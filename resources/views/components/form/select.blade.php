{{-- Constrained select — custom dropdown, accent focus ring, check on the
     selected option. The system-wide select. Values come from a curated PHP
     list; the chosen value binds to an Alpine expression on the parent scope.
     Props: model (Alpine expression), options (array of scalars),
     dir ('long'|'short'|null — tints the value), prefix (string shown before
     the value), loadingExpr / emptyExpr (Alpine bool expressions for the
     quotes-style loading/empty states), emptyMsg, disabledExpr. --}}
@props([
    'model',
    'options' => [],
    'dir' => null,
    'prefix' => '',
    'loadingExpr' => 'false',
    'emptyExpr' => 'false',
    'emptyMsg' => 'No assets on exchange',
    'disabledExpr' => 'false',
])
@php
    $valueColor = $dir === 'long' ? 'var(--pnl-up-fg)' : ($dir === 'short' ? 'var(--pnl-down-fg)' : 'var(--fg-1)');
@endphp
<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button type="button"
            :disabled="{{ $disabledExpr }} || {{ $loadingExpr }} || {{ $emptyExpr }}"
            @click="open = !open"
            :class="open ? 'border-accent shadow-[0_0_0_3px_color-mix(in_srgb,var(--accent)_18%,transparent)]' : 'border-line'"
            :style="({{ $disabledExpr }}) ? 'opacity: .5' : ''"
            class="w-full h-[42px] bg-input border rounded-control pl-3.5 pr-3 flex items-center justify-between gap-2 text-[13.5px] transition-[border-color,box-shadow] duration-fast ease-out cursor-pointer disabled:cursor-not-allowed">
        <template x-if="{{ $loadingExpr }}">
            <span class="flex items-center gap-2 text-fg-mute"><span class="w-[14px] h-[14px] rounded-full border-2 border-line-strong border-t-accent animate-spin"></span><span class="font-mono text-[12px]">Loading balances…</span></span>
        </template>
        <template x-if="!({{ $loadingExpr }}) && ({{ $emptyExpr }})">
            <span class="font-mono text-[12px] text-fg-faint">{{ $emptyMsg }}</span>
        </template>
        <template x-if="!({{ $loadingExpr }}) && !({{ $emptyExpr }})">
            <span class="font-mono font-semibold tabular-nums tracking-[0.01em] flex items-center gap-1.5" style="color: {{ $valueColor }};" x-text="{{ $prefix !== '' ? "'".$prefix."' + " : '' }}{{ $model }}"></span>
        </template>
        <span class="flex-shrink-0 text-fg-mute transition-transform duration-[180ms] ease-out" :class="open ? 'rotate-180' : ''">
            <x-feathericon-chevron-down class="w-[15px] h-[15px]" stroke-width="1.75"/>
        </span>
    </button>
    <div x-show="open" x-cloak
         class="absolute top-[calc(100%+5px)] left-0 right-0 z-[60] bg-surface border border-line rounded-control shadow-2 p-[5px] flex flex-col gap-px animate-dd-in max-h-[260px] overflow-y-auto">
        @foreach($options as $opt)
            <button type="button" @click="{{ $model }} = '{{ $opt }}'; open = false"
                    :class="{{ $model }} === '{{ $opt }}' ? 'bg-hover' : ''"
                    class="appearance-none cursor-pointer text-left flex items-center justify-between gap-3 bg-transparent border-0 rounded-[7px] py-2 px-2.5 transition-colors duration-fast ease-out hover:bg-hover">
                <span class="font-mono text-[13px] font-semibold tabular-nums"
                      @if($dir) style="color: {{ $valueColor }};" @else :class="{{ $model }} === '{{ $opt }}' ? 'text-fg-1' : 'text-fg-2'" @endif>{{ $prefix }}{{ $opt }}</span>
                <span x-show="{{ $model }} === '{{ $opt }}'" class="text-accent"><x-feathericon-check class="w-[15px] h-[15px]" stroke-width="2"/></span>
            </button>
        @endforeach
    </div>
</div>
