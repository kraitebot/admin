{{-- Text input — h-[42px], accent focus ring. The system-wide input.
     Props: model (Alpine expression the value binds to), id, placeholder,
     mono (bool), secret (bool — password with reveal toggle),
     disabledExpr (Alpine bool expression), changed (Alpine statement fired
     on input — e.g. credential edits re-locking Save). --}}
@props([
    'model',
    'id' => null,
    'placeholder' => '',
    'mono' => false,
    'secret' => false,
    'disabledExpr' => 'false',
    'changed' => null,
])
<div class="relative flex items-center" @if($secret) x-data="{ reveal: false }" @endif>
    <input @if($id) id="{{ $id }}" @endif
           @if($secret) :type="reveal ? 'text' : 'password'" @else type="text" @endif
           x-model="{{ $model }}"
           @if($changed) @input="{{ $changed }}" @endif
           :disabled="{{ $disabledExpr }}"
           placeholder="{{ $placeholder }}"
           class="peer w-full h-[42px] bg-input border border-line rounded-control px-3.5 text-[13.5px] text-fg-1 placeholder:text-fg-faint outline-none transition-[border-color,box-shadow] duration-fast ease-out focus:border-accent focus:shadow-[0_0_0_3px_color-mix(in_srgb,var(--accent)_18%,transparent)] disabled:opacity-50 disabled:cursor-not-allowed {{ $mono ? 'font-mono tracking-[0.01em]' : 'font-sans' }} {{ $secret ? 'pr-[42px]' : '' }}"/>
    @if($secret)
        <button type="button" @click="reveal = !reveal" :aria-label="reveal ? 'Hide' : 'Reveal'"
                class="absolute right-1.5 w-[32px] h-[32px] inline-flex items-center justify-center rounded-[7px] bg-transparent border-0 text-fg-mute hover:text-fg-1 hover:bg-hover transition-colors duration-fast cursor-pointer">
            <span x-show="!reveal"><x-feathericon-eye class="w-4 h-4" stroke-width="1.75"/></span>
            <span x-show="reveal" x-cloak><x-feathericon-eye-off class="w-4 h-4" stroke-width="1.75"/></span>
        </button>
    @endif
</div>
