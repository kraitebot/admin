@props(['topic'])

{{--
    Inline help affordance for metric labels (the codebase port of the design's
    BtHelp). Renders a subtle feather help glyph; hover surfaces the short tip,
    click opens the detailed explainer modal.

    `openHelp()` and the `HELP_META` registry (which carries both the short tip
    `s` and the long modal body `b`) live on the btConsole Alpine scope this
    component is always rendered within — so it is backtesting-console only by
    design. Topic is a HELP_META key.
--}}
<button type="button"
        x-on:click.stop="openHelp('{{ $topic }}')"
        :title="(HELP_META['{{ $topic }}'] || {}).s"
        class="inline-flex items-center justify-center align-middle flex-shrink-0 w-[13px] h-[13px] text-fg-mute hover:text-accent transition-colors duration-fast cursor-pointer"
        aria-label="Explain this metric">
    <x-feathericon-help-circle class="w-[13px] h-[13px]" stroke-width="1.85"/>
</button>
