{{--
    Global help/explainer modal. Rendered ONCE by the app layout and driven by
    the `help` Alpine store (resources/js/app.js) — so any `<x-ui.help-dot>` on
    any page can open it with no per-page boilerplate.

    Uses x-show (not x-if) so Alpine's transitions run on BOTH enter and leave —
    a plain opacity fade in/out of the whole overlay (dim + blur + box).

    Blur is set inline, not via Tailwind's `backdrop-blur-*` utility: this build
    doesn't emit the companion `--tw-backdrop-*` defaults, so that utility's
    backdrop-filter var-chain is invalid and Safari drops it (only the dim
    shows). An explicit filter always applies.
--}}
<div x-data>
    <div x-show="$store.help.open"
         x-cloak
         x-transition.opacity.duration.200ms
         class="fixed inset-0 z-[80] flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.45); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);"
         x-on:mousedown="$store.help.close()" x-on:keydown.escape.window="$store.help.close()">
        <div class="w-[480px] max-w-full bg-surface border border-line-strong rounded-control shadow-3 overflow-hidden" x-on:mousedown.stop>
            <div class="flex items-center gap-2.5 py-3 px-5 bg-surface-2 border-b border-line-soft">
                <span class="w-[28px] h-[28px] rounded-control flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent)">
                    <x-feathericon-help-circle class="w-[16px] h-[16px]" stroke-width="1.75"/>
                </span>
                <h4 class="font-sans font-bold text-[15px] text-fg-1" x-text="$store.help.title"></h4>
                <button type="button" x-on:click="$store.help.close()" class="appearance-none bg-transparent border-0 p-0 ml-auto w-[28px] h-[28px] rounded-control inline-flex items-center justify-center text-fg-mute hover:text-fg-1 hover:bg-hover transition-colors duration-fast cursor-pointer">
                    <x-feathericon-x class="w-4 h-4" stroke-width="2"/>
                </button>
            </div>
            <div class="p-5 max-h-[60vh] overflow-y-auto" x-html="$store.help.renderMd($store.help.body)"></div>
        </div>
    </div>
</div>
