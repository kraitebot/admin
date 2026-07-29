<?php

declare(strict_types=1);

use App\Models\User;
use Kraite\Core\Support\Financial\DayBasisLocationHint;
use Kraite\Core\Support\Financial\ReportingDay;

/**
 * The trading day basis matches the trader's exchange, not their address. So
 * arriving in a new country never changes it on its own — it offers, once, and
 * a declined offer stays declined.
 *
 * Bruno's case on 2026-07-29: in Lisbon (UTC+1), basis UTC+2 to match Binance.
 * Silently "correcting" him to UTC+1 would have re-broken the alignment we had
 * just built.
 */
it('offers a switch when the country implies a different day basis', function (): void {
    $user = User::factory()->create(['utc_offset_minutes' => 120, 'basis_hint_country' => null]);

    $hint = DayBasisLocationHint::for($user, 'PT');

    expect($hint)->not->toBeNull()
        ->and($hint->countryCode)->toBe('PT')
        ->and($hint->countryName)->toBe('Portugal')
        ->and($hint->suggestedOffsetMinutes)->toBe(60)
        ->and($hint->suggestedLabel)->toBe('UTC+01:00')
        ->and($hint->currentLabel)->toBe('UTC+02:00');
});

it('stays quiet when the country already matches the configured basis', function (): void {
    $user = User::factory()->create(['utc_offset_minutes' => 60]);

    expect(DayBasisLocationHint::for($user, 'PT'))->toBeNull();
});

it('offers a country only once, however many pages are loaded', function (): void {
    $user = User::factory()->create(['utc_offset_minutes' => 120]);

    $first = DayBasisLocationHint::for($user, 'PT');
    $first->remember();

    expect($first)->not->toBeNull()
        ->and(DayBasisLocationHint::for($user->fresh(), 'PT'))->toBeNull();
});

it('offers again when the trader moves somewhere new', function (): void {
    $user = User::factory()->create(['utc_offset_minutes' => 120, 'basis_hint_country' => 'PT']);

    $hint = DayBasisLocationHint::for($user, 'JP');

    expect($hint)->not->toBeNull()
        ->and($hint->countryName)->toBe('Japan')
        ->and($hint->suggestedOffsetMinutes)->toBe(540);
});

it('says nothing when the edge does not report a country', function (?string $country): void {
    $user = User::factory()->create(['utc_offset_minutes' => 120]);

    expect(DayBasisLocationHint::for($user, $country))->toBeNull();
})->with([
    'header absent' => null,
    'empty header' => '',
    'anonymised by the CDN' => 'XX',
    'not a country code' => 'ZZZZ',
]);

it('says nothing for a country whose offset we cannot pin down', function (): void {
    // The United States spans six bases; a country code alone cannot choose.
    $user = User::factory()->create(['utc_offset_minutes' => 120]);

    expect(DayBasisLocationHint::for($user, 'US'))->toBeNull();
});

it('records where the trader was last seen even when it has nothing to offer', function (): void {
    $user = User::factory()->create(['utc_offset_minutes' => 60, 'last_seen_country' => 'CH']);

    DayBasisLocationHint::for($user, 'PT');

    expect($user->fresh()->last_seen_country)->toBe('PT');
});

it('only suggests a basis the profile itself would accept', function (): void {
    $selectable = array_keys(ReportingDay::selectableOffsets());
    $offered = 0;

    foreach (['PT', 'CH', 'JP', 'IN', 'NP', 'BR', 'AU', 'AE'] as $country) {
        // A fresh trader per country: the hint fires once per country, and
        // reusing one would silence every country after the first.
        $user = User::factory()->create(['utc_offset_minutes' => 0]);
        $hint = DayBasisLocationHint::for($user, $country);

        if ($hint === null) {
            continue;
        }

        $offered++;
        expect($selectable)->toContain($hint->suggestedOffsetMinutes);
    }

    // Brazil and Australia span several bases and are deliberately skipped.
    expect($offered)->toBe(6);
});
