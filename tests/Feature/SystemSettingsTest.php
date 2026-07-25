<?php

declare(strict_types=1);

use App\Models\User;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\ModelLog;

function settingsAdmin(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'settings-admin-'.str()->uuid().'@example.test',
        'is_admin' => true,
    ], $attributes));
}

it('shows database-backed runtime controls and read-only engine state to admins', function (): void {
    $admin = settingsAdmin();

    $this->actingAs($admin)
        ->get(route('system.settings'))
        ->assertOk()
        ->assertSee('Runtime settings')
        ->assertSee('Master trading')
        ->assertSee('BSCS score')
        ->assertSee('0 purges immediately')
        ->assertSee(route('system.settings.update'), false);
});

it('prevents guests and traders from changing runtime settings', function (): void {
    $before = Kraite::findOrFail(1)->getRawOriginal();
    $payload = [
        'allow_opening_positions' => 1,
        'can_trade' => 1,
        'notifications_enabled' => 1,
        'td_correlation_type' => 'rolling',
        'corr_enabled' => 1,
        'elast_enabled' => 1,
        'trail_retention_hours' => 24,
        'bscs_freshness_max_seconds' => 6900,
    ];

    $this->patch(route('system.settings.update'), $payload)
        ->assertRedirect();

    expect(Kraite::findOrFail(1)->getRawOriginal())->toBe($before);

    $trader = User::factory()->create([
        'email' => 'settings-trader-'.str()->uuid().'@example.test',
        'is_admin' => false,
    ]);

    $this->actingAs($trader)
        ->patch(route('system.settings.update'), $payload)
        ->assertForbidden();

    expect(Kraite::findOrFail(1)->getRawOriginal())->toBe($before);
});

it('persists every editable runtime setting including explicit false values', function (): void {
    $admin = settingsAdmin();
    $before = Kraite::findOrFail(1);

    expect($before->allow_opening_positions)->toBeFalse()
        ->and($before->can_trade)->toBeNull()
        ->and($before->td_correlation_type)->toBeNull()
        ->and($before->bscs_block_threshold)->toBe(80);

    $this->actingAs($admin)
        ->patch(route('system.settings.update'), [
            'allow_opening_positions' => 0,
            'can_trade' => 0,
            'notifications_enabled' => 0,
            'td_correlation_type' => 'spearman',
            'corr_enabled' => 0,
            'elast_enabled' => 1,
            'trail_retention_hours' => 48,
            'bscs_freshness_max_seconds' => 7200,
        ])
        ->assertRedirect(route('system.settings'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Runtime settings saved.');

    $engine = Kraite::findOrFail(1);

    expect($engine->allow_opening_positions)->toBeFalse()
        ->and($engine->can_trade)->toBeFalse()
        ->and($engine->notifications_enabled)->toBeFalse()
        ->and($engine->td_correlation_type)->toBe('spearman')
        ->and($engine->corr_enabled)->toBeFalse()
        ->and($engine->elast_enabled)->toBeTrue()
        ->and($engine->trail_retention_hours)->toBe(48)
        ->and($engine->bscs_freshness_max_seconds)->toBe(7200)
        ->and(Kraite::canTrade())->toBeFalse()
        ->and(Kraite::notificationsEnabled())->toBeFalse()
        ->and(Kraite::correlationType())->toBe('spearman');
});

it('preserves inherited defaults as null overrides', function (): void {
    $admin = settingsAdmin();

    $this->actingAs($admin)
        ->patch(route('system.settings.update'), [
            'allow_opening_positions' => 1,
            'can_trade' => 'inherit',
            'notifications_enabled' => 'inherit',
            'td_correlation_type' => 'inherit',
            'corr_enabled' => 'inherit',
            'elast_enabled' => 'inherit',
            'trail_retention_hours' => null,
            'bscs_freshness_max_seconds' => 6900,
        ])
        ->assertSessionHasNoErrors();

    $engine = Kraite::findOrFail(1);

    expect($engine->can_trade)->toBeNull()
        ->and($engine->notifications_enabled)->toBeNull()
        ->and($engine->td_correlation_type)->toBeNull()
        ->and($engine->corr_enabled)->toBeNull()
        ->and($engine->elast_enabled)->toBeNull()
        ->and($engine->trail_retention_hours)->toBeNull();
});

it('rejects invalid runtime settings without changing the singleton', function (): void {
    $admin = settingsAdmin();
    $before = Kraite::findOrFail(1)->getRawOriginal();

    $this->actingAs($admin)
        ->from(route('system.settings'))
        ->patch(route('system.settings.update'), [
            'allow_opening_positions' => 1,
            'can_trade' => 'yes',
            'notifications_enabled' => 1,
            'td_correlation_type' => 'invented',
            'corr_enabled' => 'on',
            'elast_enabled' => 1,
            'trail_retention_hours' => -1,
            'bscs_freshness_max_seconds' => -1,
        ])
        ->assertRedirect(route('system.settings'))
        ->assertInvalid([
            'can_trade',
            'td_correlation_type',
            'corr_enabled',
            'trail_retention_hours',
            'bscs_freshness_max_seconds',
        ]);

    expect(Kraite::findOrFail(1)->getRawOriginal())->toBe($before);
});

it('does not write an audit event when submitted values do not change', function (): void {
    $admin = settingsAdmin();

    $this->actingAs($admin)
        ->patch(route('system.settings.update'), [
            'allow_opening_positions' => 0,
            'can_trade' => 'inherit',
            'notifications_enabled' => 0,
            'td_correlation_type' => 'inherit',
            'corr_enabled' => 'inherit',
            'elast_enabled' => 'inherit',
            'trail_retention_hours' => null,
            'bscs_freshness_max_seconds' => 6900,
        ])
        ->assertSessionHasNoErrors();

    expect(ModelLog::query()
        ->where('event_type', 'runtime_settings_updated')
        ->exists())->toBeFalse();
});

it('audits a sanitized before and after snapshot with the admin actor', function (): void {
    $admin = settingsAdmin(['name' => 'Runtime Operator']);

    $this->actingAs($admin)
        ->patch(route('system.settings.update'), [
            'allow_opening_positions' => 1,
            'can_trade' => 1,
            'notifications_enabled' => 0,
            'td_correlation_type' => 'pearson',
            'corr_enabled' => 1,
            'elast_enabled' => 0,
            'trail_retention_hours' => 24,
            'bscs_freshness_max_seconds' => 7000,
        ])
        ->assertSessionHasNoErrors();

    $log = ModelLog::query()
        ->where('event_type', 'runtime_settings_updated')
        ->sole();

    expect($log->relatable_type)->toBe(User::class)
        ->and($log->relatable_id)->toBe($admin->id)
        ->and($log->metadata['actor_id'])->toBe($admin->id)
        ->and($log->metadata['actor_name'])->toBe('Runtime Operator')
        ->and($log->metadata['before'])->toHaveKeys([
            'allow_opening_positions',
            'can_trade',
            'notifications_enabled',
            'td_correlation_type',
            'corr_enabled',
            'elast_enabled',
            'trail_retention_hours',
            'bscs_freshness_max_seconds',
        ])
        ->and($log->metadata['after']['can_trade'])->toBeTrue()
        ->and($log->metadata)->not->toHaveKeys([
            'binance_api_key',
            'resend_api_key',
        ]);
});
