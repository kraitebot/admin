{{-- Toggle — pill switch; green = enabled/on (trading-enabled is safe/go).
     The system-wide switch. Props: model (Alpine bool expression),
     disabledExpr (Alpine bool expression). --}}
@props([
    'model',
    'disabledExpr' => 'false',
])
<button type="button" role="switch"
        :aria-checked="{{ $model }}"
        :disabled="{{ $disabledExpr }}"
        @click="{{ $model }} = !{{ $model }}"
        :class="({{ $disabledExpr }}) ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'"
        :style="({{ $model }}) ? 'background: var(--accent)' : 'background: var(--bg-elev-3); box-shadow: inset 0 0 0 1px var(--border-strong)'"
        class="relative inline-flex items-center h-[26px] w-[46px] rounded-chip transition-colors duration-[180ms] ease-out flex-shrink-0">
    <span class="absolute rounded-chip bg-white w-[18px] h-[18px] top-1 shadow-[0_1px_3px_rgba(0,0,0,.4)] transition-[left] duration-[180ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
          :style="({{ $model }}) ? 'left: 24px' : 'left: 4px'"></span>
</button>
