<?php

declare(strict_types=1);

it('stores dispatcher state aggregates as cache-safe scalar arrays', function (): void {
    $source = file_get_contents(app_path('Http/Controllers/System/StepDispatcherController.php'));

    expect($source)->not->toBeFalse()
        ->and(substr_count($source, '->map(static fn (object $row): array => (array) $row)'))->toBe(2)
        ->and($source)->not->toContain('$row->state')
        ->and($source)->not->toContain('$row->total');

    $cachedRows = [[
        'class' => 'App\\Jobs\\ParentJob',
        'state' => 'StepDispatcher\\States\\Running',
        'is_throttled' => 0,
        'total' => 2,
    ]];

    expect(unserialize(serialize($cachedRows), ['allowed_classes' => false]))->toBe($cachedRows);
});
