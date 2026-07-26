<?php

declare(strict_types=1);

it('centers open position values under their headers without moving the market or row action', function (): void {
    $view = file_get_contents(resource_path('views/accounts/positions.blade.php'));
    $openPositions = explode('{{-- Section separator between open and history --}}', $view, 2)[0];

    expect($openPositions)
        ->toContain("\$tdNum = 'py-[12px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-center';")
        ->toContain('<td class="py-[12px] px-3 border-b border-line-soft text-center">@include')
        ->toContain('<td class="py-[12px] pl-5 pr-3 border-b border-line-soft">')
        ->toContain('<td class="py-[12px] pr-5 pl-1 border-b border-line-soft text-right">');
});

it('lets the trader search closed positions by market and keeps the pager honest', function (): void {
    $view = file_get_contents(resource_path('views/accounts/positions.blade.php'));
    $history = explode('{{-- Section separator between open and history --}}', $view, 2)[1];

    expect($history)
        ->toContain('aria-label="Search closed positions"')
        ->toContain('x-model="query"')
        ->toContain('@keydown.escape="clearQuery()"');

    expect($view)
        // Search narrows the same row set the segmented filter works on, so
        // the page count and the footer total follow it.
        ->toContain('&& this.matchesQuery(r));')
        // A trader types the pair; markets are stored by token.
        ->toContain("const quotes = ['usdt', 'usdc', 'usd', 'busd', 'perp'];")
        ->toContain('quotes.some((quote) => q === sym + quote)')
        // Searching is a filter change: back to page one, rows collapsed.
        ->toContain("this.\$watch('query', () => { this.page = 0; this.open = null; this.persist(); this.update(); });")
        // Survives the ten-second content swap.
        ->toContain('filter: this.filter, query: this.query,')
        ->toContain("this.query = s.query ?? '';")
        // An empty result says so instead of showing a bare pager.
        ->toContain('No closed position matches that.');
});
