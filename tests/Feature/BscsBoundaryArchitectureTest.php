<?php

declare(strict_types=1);

it('keeps every admin BSCS consumer behind the unified facade', function (): void {
    $appRoot = realpath(app_path());

    expect($appRoot)->not->toBeFalse();

    $forbidden = [
        'BscsState::current()',
        'use Kraite\\Core\\Support\\MarketRegime\\CrowdingMultiplier;',
        'use Kraite\\Core\\Support\\MarketRegime\\DirectionalBookRisk;',
        'use Kraite\\Core\\Support\\MarketRegime\\FragileMarginMultiplier;',
        'use Kraite\\Core\\Support\\MarketRegime\\RegimeCountMultiplier;',
        'use Kraite\\Core\\Support\\MarketRegime\\RegimeLeverageMultiplier;',
    ];
    $violations = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            continue;
        }

        foreach ($forbidden as $token) {
            if (str_contains($source, $token)) {
                $violations[] = str_replace($appRoot.DIRECTORY_SEPARATOR, '', $file->getPathname()).': '.$token;
            }
        }
    }

    expect($violations)->toBe([]);
});
