<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    Notification::fake();
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

describe('login page', function () {
    it('shows the forgot-password link', function () {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('password.request'))
            ->assertSee('Forgot password?');
    });

    it('renders flash status as a success toast (post-reset redirect)', function () {
        $this->withSession(['status' => 'Password updated. Please log in.'])
            ->get('/login')
            ->assertOk()
            ->assertSee('Password updated. Please log in.');
    });
});

describe('forgot-password GET', function () {
    it('renders 200 for guests', function () {
        $this->get('/forgot-password')->assertOk();
    });
});

describe('forgot-password POST — silent success + per-email rate limit', function () {
    it('returns the neutral status for a known email and sends our notification', function () {
        $user = User::factory()->create([
            'email' => 'known-'.uniqid().'@kraite.test',
        ]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', 'If your email is part of our system, you will receive a reset link shortly.');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    });

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

    it('caps a single email at 5 sends within 60 seconds', function () {
        $user = User::factory()->create(['email' => 'flood-'.uniqid().'@kraite.test']);

        // Bypass the route's IP-throttle middleware so we isolate the
        // controller's per-email RateLimiter under test.
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $user->email])
                ->assertRedirect()
                ->assertSessionHas('status');
        }
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 5);

        // 6th hit: silent success but NO new notification dispatched.
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', 'If your email is part of our system, you will receive a reset link shortly.');
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 5);
    });

    it('rate-limits per-email, not globally — different emails are independent', function () {
        $a = User::factory()->create(['email' => 'a-'.uniqid().'@kraite.test']);
        $b = User::factory()->create(['email' => 'b-'.uniqid().'@kraite.test']);

        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $a->email]);
        }

        // a is now capped, but b still gets a fresh send
        $this->post('/forgot-password', ['email' => $b->email]);

        Notification::assertSentToTimes($a, ResetPasswordNotification::class, 5);
        Notification::assertSentToTimes($b, ResetPasswordNotification::class, 1);
    });
});

describe('reset password email branding', function () {
    it('uses Kraite sender name, correct subject, and the agreed copy', function () {
        $user = User::factory()->create([
            'name' => 'Pat Smith',
            'email' => 'mailcheck-'.uniqid().'@kraite.test',
        ]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            /** @var MailMessage $mail */
            $mail = $notification->toMail($user);

            expect($mail->subject)->toBe('Reset your Kraite password');
            expect($mail->from[0])->toBe(config('mail.from.address'));
            expect($mail->from[1])->toBe('Kraite');
            expect($mail->greeting)->toBe('Hi Pat Smith,');
            expect($mail->salutation)->toBe('— The Kraite team');

            $body = collect($mail->introLines)->merge($mail->outroLines)->implode("\n");
            expect($body)
                ->toContain('You requested a password reset for your Kraite account.')
                ->toContain('15 minutes')
                ->toContain('you can safely ignore this email');

            return true;
        });
    });

    it('falls back to "Hi there," when the user has no name on file', function () {
        $user = User::factory()->create([
            'name' => '',
            'email' => 'noname-'.uniqid().'@kraite.test',
        ]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
            /** @var MailMessage $mail */
            $mail = $notification->toMail($user);
            expect($mail->greeting)->toBe('Hi there,');

            return true;
        });
    });
});

describe('reset password GET', function () {
    it('renders without a name field when the user already has a name', function () {
        [$user, $token] = makeUserWithToken(['name' => 'Pat Smith']);

        $response = $this->get("/reset-password/{$token}?email=".urlencode($user->email));

        $response->assertOk();
        expect($response->getContent())
            ->not->toContain('name="name"');
    });

    it('renders WITH a name field when the user record has no name', function () {
        [$user, $token] = makeUserWithToken(['name' => '']);

        $response = $this->get("/reset-password/{$token}?email=".urlencode($user->email));

        $response->assertOk();
        expect($response->getContent())
            ->toContain('name="name"')
            ->toContain('Full name')
            ->toContain('We don&#039;t have your name on file');
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

    it('renders the expired link page with a "Request a new link" CTA', function () {
        $this->get('/reset-password-expired')
            ->assertOk()
            ->assertSee('Reset link no longer valid')
            ->assertSee(route('password.request'))
            ->assertSee('Request a new link');
    });
});
