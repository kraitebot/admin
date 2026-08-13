{{-- Constrained select — custom dropdown, accent focus ring, check on the
     selected option. The system-wide select. Values come from a curated PHP
     list; the chosen value either binds to an Alpine expression on the parent
     scope or submits through a named hidden input.
     Props: model (Alpine expression), name/id/value (form mode), options
     (scalar list or value => label map),
     dir ('long'|'short'|null — tints the value), prefix (string shown before
     the value), loadingExpr / emptyExpr (Alpine bool expressions for the
     quotes-style loading/empty states), emptyMsg, disabledExpr. --}}
@props([
    'model' => null,
    'name' => null,
    'id' => null,
    'value' => null,
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
    $optionsAreList = array_is_list($options);
    $normalizedOptions = collect($options)
        ->mapWithKeys(static fn (mixed $label, int|string $optionValue): array => $optionsAreList
            ? [(string) $label => (string) $label]
            : [(string) $optionValue => (string) $label])
        ->all();
    $formMode = is_string($name) && $name !== '';
    $selection = $formMode ? 'selected' : $model;
@endphp
<div class="relative"
     x-data="{ open: false, labels: @js($normalizedOptions){{ $formMode ? ', selected: '.Illuminate\Support\Js::from((string) $value) : '' }} }"
     @click.outside="open = false"
     @keydown.escape.window="open = false">
    @if($formMode)
        <input type="hidden" name="{{ $name }}" :value="{{ $selection }}">
    @endif
    <button type="button"
            @if($id) id="{{ $id }}" @endif
            aria-haspopup="listbox"
            :aria-expanded="open"
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
            <span class="font-mono font-semibold tabular-nums tracking-[0.01em] flex items-center gap-1.5" style="color: {{ $valueColor }};" x-text="@js($prefix) + (labels[{{ $selection }}] ?? {{ $selection }})"></span>
        </template>
        <span class="flex-shrink-0 text-fg-mute transition-transform duration-[180ms] ease-out" :class="open ? 'rotate-180' : ''">
            <x-feathericon-chevron-down class="w-[15px] h-[15px]" stroke-width="1.75"/>
        </span>
    </button>
    <div x-show="open" x-cloak
         role="listbox"
         class="absolute top-[calc(100%+5px)] left-0 right-0 z-[60] bg-surface border border-line rounded-control shadow-2 p-[5px] flex flex-col gap-px animate-dd-in max-h-[260px] overflow-y-auto">
        @foreach($normalizedOptions as $optionValue => $optionLabel)
            <button type="button"
                    role="option"
                    @click="{{ $selection }} = @js((string) $optionValue); open = false"
                    :aria-selected="{{ $selection }} === @js((string) $optionValue)"
                    :class="{{ $selection }} === @js((string) $optionValue) ? 'bg-hover' : ''"
                    class="appearance-none cursor-pointer text-left flex items-center justify-between gap-3 bg-transparent border-0 rounded-[7px] py-2 px-2.5 transition-colors duration-fast ease-out hover:bg-hover">
                <span class="font-mono text-[13px] font-semibold tabular-nums"
                      @if($dir) style="color: {{ $valueColor }};" @else :class="{{ $selection }} === @js((string) $optionValue) ? 'text-fg-1' : 'text-fg-2'" @endif>{{ $prefix }}{{ $optionLabel }}</span>
                <span x-show="{{ $selection }} === @js((string) $optionValue)" class="text-accent"><x-feathericon-check class="w-[15px] h-[15px]" stroke-width="2"/></span>
            </button>
        @endforeach
    </div>
</div>
