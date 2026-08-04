<?php

declare(strict_types=1);

it('does not own the production fleet heartbeat cadence', function (): void {
    expect(file_get_contents(base_path('routes/console.php')))
        ->not->toContain('kraite:fleet-report');
});
