<?php

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\MarketRegime\Bscs;

it('renders help for the BSCS score and each component', function (): void {
    $html = view('dashboard', [
        'accounts' => collect(),
        'initialAccountId' => null,
        'initialPayload' => null,
    ])->render();

    expect($html)->toContain(
        'Black Swan Composite Score',
        'Market-wide risk score used to adjust or pause new positions.',
        'New registrations save a maximum of',
        'Is BTC moving much faster than its recent norm?',
        'Is today&#039;s BTC trading range unusually wide?',
        'Are the major coins all moving together?',
        'How far has BTC fallen from its recent high?',
        'Is BTC futures activity unusually high?',
        'BSCS position cap',
        'd.bscs.position_cap.long.effective',
        'd.bscs.position_cap.long.maximum',
        'Saved limit unchanged; BSCS does not close existing positions.',
    );
});

it('derives the admin BSCS position cap from the saved account maximum', function (?int $score, int $effective, int $ratioPercent): void {
    config()->set('kraite.market_regime.count_ratio.elevated', 0.75);
    config()->set('kraite.market_regime.count_ratio.fragile', 0.50);

    $account = new Account;
    $account->total_positions_long = 6;
    $account->total_positions_short = 6;
    DB::table('kraite')->where('id', 1)->update([
        'bscs_score' => $score,
        'bscs_band' => $score === null ? null : match (true) {
            $score >= 80 => 'critical',
            $score >= 60 => 'fragile',
            $score >= 40 => 'elevated',
            default => 'calm',
        },
        'bscs_synced_at' => $score === null ? null : now(),
    ]);

    expect(Bscs::forAccount($account)->positions()->max()->toArray())->toBe([
        'long' => ['effective' => $effective, 'maximum' => 6],
        'short' => ['effective' => $effective, 'maximum' => 6],
        'ratio_percent' => $ratioPercent,
    ]);
})->with([
    'before first BSCS compute' => [null, 6, 100],
    'calm' => [39, 6, 100],
    'elevated' => [40, 4, 75],
    'fragile' => [60, 3, 50],
    'critical' => [80, 0, 0],
]);

it('keeps a legacy 1+1 maximum saved while BSCS rounds its temporary cap down', function (int $score, int $ratioPercent): void {
    config()->set('kraite.market_regime.count_ratio.elevated', 0.75);
    config()->set('kraite.market_regime.count_ratio.fragile', 0.50);

    $account = new Account;
    $account->total_positions_long = 1;
    $account->total_positions_short = 1;
    DB::table('kraite')->where('id', 1)->update([
        'bscs_score' => $score,
        'bscs_band' => $score >= 80 ? 'critical' : ($score >= 60 ? 'fragile' : 'elevated'),
        'bscs_synced_at' => now(),
    ]);

    expect([$account->total_positions_long, $account->total_positions_short])->toBe([1, 1]);

    expect(Bscs::forAccount($account)->positions()->max()->toArray())->toBe([
        'long' => ['effective' => 0, 'maximum' => 1],
        'short' => ['effective' => 0, 'maximum' => 1],
        'ratio_percent' => $ratioPercent,
    ])->and([$account->total_positions_long, $account->total_positions_short])->toBe([1, 1]);
})->with([
    'elevated' => [40, 75],
    'fragile' => [60, 50],
    'critical' => [80, 0],
]);
