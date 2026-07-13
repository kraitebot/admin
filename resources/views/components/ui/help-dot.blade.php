@props(['topic' => null, 'title' => null, 'body' => null, 'tip' => null])

{{--
    Inline help affordance — a subtle feather [?] glyph. Hover shows a short
    tip; click opens the global help modal (the `help` Alpine store + the
    single <x-ui.help-modal> in the layout). Drop it anywhere, no page setup.

    Two content modes:
      - Inline (most cases):
          <x-ui.help-dot title="Month type" :body="$markdown" tip="Short hover tip"/>
      - Registry topic (for pages with many rich entries, e.g. backtesting):
          page calls $store.help.register({ key: { t, s, b } }); then
          <x-ui.help-dot topic="key"/>
--}}
<button type="button"
        {{-- Stop pointerdown reaching a draggable ancestor (e.g. the dashboard
             position carousel), which would capture the pointer and swallow
             this button's click. --}}
        x-on:pointerdown.stop=""
        @if($topic !== null)
            x-on:click.stop="$store.help.show('{{ $topic }}')"
            :title="($store.help.registry['{{ $topic }}'] || {}).s"
        @else
            x-on:click.stop="$store.help.showInline(@js($title), @js($body))"
            @if($tip) title="{{ $tip }}" @endif
        @endif
        class="appearance-none bg-transparent border-0 p-0 leading-none inline-flex items-center justify-center align-middle flex-shrink-0 w-[13px] h-[13px] text-fg-mute hover:text-accent transition-colors duration-fast cursor-pointer"
        aria-label="Explain this">
    <x-feathericon-help-circle class="w-[13px] h-[13px]" stroke-width="1.85"/>
</button>
