<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('escapes form help text before rendering it into the page', function (): void {
    $html = Blade::render(
        '<x-form.field label="Risk" :help="$help"><input></x-form.field>',
        ['help' => '<img src=x onerror="window.compromised=true">'],
    );

    expect($html)
        ->not->toContain('<img')
        ->toContain('&lt;img');
});
