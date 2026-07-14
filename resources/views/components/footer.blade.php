@php
    // Real deployed version: the first semver heading in CHANGELOG.md.
    // Cached — the file only changes at release time. The old placeholder
    // nav links (Status / Terms / …) pointed nowhere and are gone until
    // those pages exist.
    $version = \Illuminate\Support\Facades\Cache::remember('admin.footer-version', 3600, function (): string {
        $changelog = @file_get_contents(base_path('CHANGELOG.md')) ?: '';

        return preg_match('/^## \[?v?(\d+\.\d+\.\d+)\]?/m', $changelog, $m) === 1
            ? 'v'.$m[1]
            : 'dev';
    });
@endphp
<footer data-shell-footer
        class="col-span-full grid grid-cols-[112px_minmax(0,1fr)] min-h-[41px] max-[820px]:min-h-[64px] bg-[#07090b] border-t border-ink-3 max-[640px]:grid-cols-1">
    <div data-rail-status
         class="flex items-center justify-center max-[640px]:hidden">
        <div class="w-2 h-2 rounded-chip bg-green-500" title="Engine online"></div>
    </div>
    <div class="min-w-0 min-h-[41px] max-[820px]:min-h-[64px] py-2.5 px-8 flex items-center gap-5
                max-[820px]:flex-wrap max-[820px]:gap-x-4 max-[820px]:gap-y-2 max-[820px]:px-4 max-[640px]:col-span-full">
        <span class="font-mono text-[10px] font-semibold tracking-[0.06em] text-green-600 bg-green-25 border border-green-50 rounded-chip py-[3px] px-2.5 whitespace-nowrap">{{ $version }}</span>
        <span class="flex-1 max-[820px]:hidden"></span>
        <span class="font-mono text-[10px] text-ink-6 tracking-[0.02em] whitespace-nowrap max-[820px]:whitespace-normal max-[820px]:basis-full max-[820px]:order-9">
            Autonomous trading carries risk of total loss. Past survival is not a guarantee. Not financial advice.
        </span>
    </div>
</footer>
