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
