<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Closure for Notification::assertSentTo/Times callbacks that only matches
 * AlertNotifications carrying the password-reset canonical — so unrelated
 * AlertNotifications fired during the same request (e.g. observers, log
 * listeners) don't accidentally satisfy these assertions.
 */
function isPasswordResetNotification(): Closure
{
    return fn (AlertNotification $notification): bool => $notification->canonical === 'password_reset';
}

beforeEach(function () {
    Notification::fake();

    // The array cache store persists for the whole PHPUnit process, so
    // rate-limiter hits accumulate ACROSS tests — whether two tests share
    // a limiter minute-bucket then depends on wall-clock time, making the
    // throttle-adjacent tests flaky by time of day. Reset per test.
    Cache::flush();
});

/**
 * Build a user + valid reset token in one shot, returning [User, token].
 *
 * @return array{0: User, 1: string}
 */
function makeUserWithToken(array $overrides = []): array
{
    $user = User::factory()->create($overrides);
    $token = Password::createToken($user);

    return [$user, $token];
}

describe('forgot-password POST — silent success + per-email rate limit', function () {
    it('returns the SAME neutral status for an unknown email and sends nothing', function () {
        $unknown = 'ghost-'.uniqid().'@kraite.test';

        $this->post('/forgot-password', ['email' => $unknown])
            ->assertRedirect()
            ->assertSessionHas('status', 'If your email is part of our system, you will receive a reset link shortly.');

        Notification::assertNothingSent();
        expect(User::where('email', $unknown)->exists())->toBeFalse();
    });

    it('does not leak which addresses are registered (identical flash message)', function () {
        $known = User::factory()->create(['email' => 'known-'.uniqid().'@kraite.test']);
        $unknown = 'ghost-'.uniqid().'@kraite.test';

        $a = $this->post('/forgot-password', ['email' => $known->email])->getSession()->get('status');
        $b = $this->post('/forgot-password', ['email' => $unknown])->getSession()->get('status');

        expect($a)->toBe($b);
    });

});

describe('reset password POST — happy path', function () {
    it('updates the password, redirects to /login, sets the status flash, fires PasswordReset event', function () {
        Event::fake([PasswordReset::class]);
        [$user, $token] = makeUserWithToken(['name' => 'Pat Smith']);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'GoodPass1',
            'password_confirmation' => 'GoodPass1',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Password updated. Please log in.')
            ->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        expect(Hash::check('GoodPass1', $fresh->password))->toBeTrue();
        expect($fresh->name)->toBe('Pat Smith');
        Event::assertDispatched(PasswordReset::class, fn ($e) => $e->user->is($user));
    });

    it('captures the name when the user record has no name', function () {
        [$user, $token] = makeUserWithToken(['name' => '']);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'name' => 'Sky River',
            'password' => 'GoodPass1',
            'password_confirmation' => 'GoodPass1',
        ])->assertRedirect(route('login'));

        expect($user->fresh()->name)->toBe('Sky River');
    });

    it('does NOT overwrite an existing name even if a name field is submitted', function () {
        [$user, $token] = makeUserWithToken(['name' => 'Original Name']);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'name' => 'Hijacker Name',
            'password' => 'GoodPass1',
            'password_confirmation' => 'GoodPass1',
        ])->assertRedirect(route('login'));

        expect($user->fresh()->name)->toBe('Original Name');
    });
});

describe('reset password POST — validation', function () {
    it('rejects passwords shorter than 8 characters', function () {
        [$user, $token] = makeUserWithToken();

        $this->from('/reset-password/'.$token)
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'Ab1',
                'password_confirmation' => 'Ab1',
            ])
            ->assertSessionHasErrors('password');

        expect(Hash::check('Ab1', $user->fresh()->password))->toBeFalse();
    });

    it('rejects passwords with no uppercase letter', function () {
        [$user, $token] = makeUserWithToken();

        $this->from('/reset-password/'.$token)
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'lowercase1',
                'password_confirmation' => 'lowercase1',
            ])
            ->assertSessionHasErrors('password');
    });

    it('rejects passwords with no lowercase letter', function () {
        [$user, $token] = makeUserWithToken();

        $this->from('/reset-password/'.$token)
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'UPPER123',
                'password_confirmation' => 'UPPER123',
            ])
            ->assertSessionHasErrors('password');
    });

    it('rejects passwords with no digit', function () {
        [$user, $token] = makeUserWithToken();

        $this->from('/reset-password/'.$token)
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'NoDigitsHere',
                'password_confirmation' => 'NoDigitsHere',
            ])
            ->assertSessionHasErrors('password');
    });

    it('rejects mismatched password confirmation', function () {
        [$user, $token] = makeUserWithToken();

        $this->from('/reset-password/'.$token)
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'GoodPass1',
                'password_confirmation' => 'GoodPass2',
            ])
            ->assertSessionHasErrors('password');
    });

    it('requires the name field when the user record has no name', function () {
        [$user, $token] = makeUserWithToken(['name' => '']);

        $this->from('/reset-password/'.$token)
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                // name omitted on purpose
                'password' => 'GoodPass1',
                'password_confirmation' => 'GoodPass1',
            ])
            ->assertSessionHasErrors('name');

        expect($user->fresh()->name ?? '')->toBe('');
    });
});

describe('reset password POST — invalid token paths', function () {
    it('redirects to the expired page when the token never existed', function () {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'never-was-a-token',
            'email' => $user->email,
            'password' => 'GoodPass1',
            'password_confirmation' => 'GoodPass1',
        ])->assertRedirect(route('password.expired'));

        expect(Hash::check('GoodPass1', $user->fresh()->password))->toBeFalse();
    });

    it('rejects the second attempt with a single-use token (single-use enforcement)', function () {
        [$user, $token] = makeUserWithToken();

        // First use succeeds.
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'FirstPass1',
            'password_confirmation' => 'FirstPass1',
        ])->assertRedirect(route('login'));

        // Second use of the same token is rejected.
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'SecondPass1',
            'password_confirmation' => 'SecondPass1',
        ])->assertRedirect(route('password.expired'));

        // Password must be the FIRST one — second attempt cannot have flipped it.
        expect(Hash::check('FirstPass1', $user->fresh()->password))->toBeTrue();
        expect(Hash::check('SecondPass1', $user->fresh()->password))->toBeFalse();
    });

    it('rejects a token older than the 15-minute window', function () {
        [$user, $token] = makeUserWithToken();

        // Backdate the token by 16 minutes.
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(16)]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'GoodPass1',
            'password_confirmation' => 'GoodPass1',
        ])->assertRedirect(route('password.expired'));

        expect(Hash::check('GoodPass1', $user->fresh()->password))->toBeFalse();
    });

});
