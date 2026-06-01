@php
    $links = ['Status', 'Audit log', 'Risk policy', 'Terms', 'Support'];
    $version = 'v4.2.1 · build 20260531';
@endphp
<footer class="flex-shrink-0 bg-[#07090b] border-t border-ink-3 py-2.5 px-8 flex items-center gap-5
               max-[820px]:flex-wrap max-[820px]:gap-x-4 max-[820px]:gap-y-2 max-[820px]:px-4">
    <span class="font-mono text-[10px] font-semibold tracking-[0.06em] text-green-600 bg-green-25 border border-green-50 rounded-chip py-[3px] px-2.5 whitespace-nowrap">{{ $version }}</span>
    <nav class="flex items-center gap-4">
        @foreach($links as $l)
            <a href="#" class="font-mono text-[11px] text-ink-7 no-underline tracking-[0.02em] whitespace-nowrap hover:text-ink-9">{{ $l }}</a>
        @endforeach
    </nav>
    <span class="flex-1 max-[820px]:hidden"></span>
    <span class="font-mono text-[10px] text-ink-6 tracking-[0.02em] whitespace-nowrap max-[820px]:whitespace-normal max-[820px]:basis-full max-[820px]:order-9">
        Autonomous trading carries risk of total loss. Past survival is not a guarantee. Not financial advice.
    </span>
</footer>
