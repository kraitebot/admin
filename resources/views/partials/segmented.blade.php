{{-- Segmented filter control with the sliding green pill. Expects the
     surrounding Alpine scope to expose filter / setFilter(). Params: $options. --}}
<div x-data="{
        segHl: null,
        measure() {
            const el = this.$el.querySelector('[data-seg-active]');
            this.segHl = el ? { left: el.offsetLeft, top: el.offsetTop, width: el.offsetWidth, height: el.offsetHeight } : null;
        },
     }"
     x-init="
        $nextTick(() => measure());
        window.addEventListener('resize', () => measure());
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => measure());
        $watch('filter', () => $nextTick(() => measure()));
     "
     class="relative inline-flex items-center h-[34px] bg-surface-3 border border-line rounded-control px-[3px] gap-0.5">
    <span aria-hidden="true"
          x-show="segHl"
          x-cloak
          :style="segHl ? `left:${segHl.left}px;top:${segHl.top}px;width:${segHl.width}px;height:${segHl.height}px` : ''"
          class="absolute z-0 bg-accent rounded-[7px] shadow-1 pointer-events-none transition-all duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)]"></span>
    @foreach($options as $opt)
        <button type="button" @click="setFilter('{{ $opt }}')"
                :data-seg-active="filter === '{{ $opt }}' ? '' : null"
                :class="filter === '{{ $opt }}' ? 'text-accent-on' : 'text-fg-3 hover:text-fg-1'"
                class="appearance-none bg-transparent border-0 rounded-[7px] h-[26px] inline-flex items-center px-3 font-mono text-[11px] font-semibold tracking-[0.04em] cursor-pointer relative z-[1] transition-colors duration-fast ease-out">{{ $opt }}</button>
    @endforeach
</div>
