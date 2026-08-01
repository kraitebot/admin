<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\PasskeyChallengeStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Support\WebAuthn;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    if (! Schema::hasTable('personal_access_tokens')) {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }
});

function passkeyApiUrl(string $path): string
{
    return 'https://'.config('domains.api').$path;
}

/** @return array<string, mixed> */
function passkeyAssertionCredential(): array
{
    $base64Url = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $domain = (string) config('domains.api');
    $authenticatorData = hash('sha256', $domain, binary: true).chr(1).pack('N', 0);

    return [
        'id' => $base64Url('passkey-credential-id'),
        'rawId' => $base64Url('passkey-credential-id'),
        'type' => 'public-key',
        'response' => [
            'authenticatorData' => $base64Url($authenticatorData),
            'clientDataJSON' => $base64Url(json_encode([
                'type' => 'webauthn.get',
                'challenge' => $base64Url(str_repeat('c', 32)),
                'origin' => 'https://'.$domain,
            ], JSON_THROW_ON_ERROR)),
            'signature' => $base64Url(str_repeat("\0", 64)),
        ],
    ];
}

it('serves the exact Apple association for the signed Kraite app', function (): void {
    $this->getJson(passkeyApiUrl('/.well-known/apple-app-site-association'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([
            'applinks' => [],
            'webcredentials' => [
                'apps' => ['FWCW3LG29Y.com.kraite.app'],
            ],
            'appclips' => [],
        ]);
});

it('reports whether password login account already has a passkey', function (): void {
    $withoutPasskey = User::factory()->create([
        'name' => 'Mobile passkey absent trader',
        'email' => 'mobile-passkey-absent@kraite.test',
        'password' => 'correct-password',
    ]);
    $withPasskey = User::factory()->create([
        'name' => 'Mobile passkey present trader',
        'email' => 'mobile-passkey-present@kraite.test',
        'password' => 'correct-password',
    ]);
    $withPasskey->passkeys()->create([
        'name' => 'Present iPhone',
        'credential_id' => 'present-credential-id',
        'credential' => [],
    ]);

    $this->postJson(passkeyApiUrl('/v1/auth/token'), [
        'email' => $withoutPasskey->email,
        'password' => 'correct-password',
        'device_name' => 'Absent iPhone',
    ])->assertOk()->assertJsonPath('passkeys_enabled', false);

    $this->postJson(passkeyApiUrl('/v1/auth/token'), [
        'email' => $withPasskey->email,
        'password' => 'correct-password',
        'device_name' => 'Present iPhone',
    ])->assertOk()->assertJsonPath('passkeys_enabled', true);

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $withoutPasskey->id)->value('name'))->toBe('Absent iPhone')
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $withPasskey->id)->value('name'))->toBe('Present iPhone');
});

it('requires a valid scoped session before exposing registration options', function (): void {
    $this->getJson(passkeyApiUrl('/v1/passkeys/register/options'))->assertUnauthorized();

    $user = User::factory()->create([
        'name' => 'Mobile registration owner',
        'email' => 'mobile-registration-owner@kraite.test',
    ]);
    Sanctum::actingAs($user, ['dashboard:read']);

    $response = $this->getJson(passkeyApiUrl('/v1/passkeys/register/options'))
        ->assertOk()
        ->assertJsonPath('options.rp.id', config('domains.api'))
        ->assertJsonPath('options.user.name', $user->email)
        ->assertJsonPath('options.user.displayName', $user->name)
        ->assertJsonPath('options.authenticatorSelection.residentKey', 'required')
        ->assertJsonPath('options.authenticatorSelection.userVerification', 'required');

    expect($response->json('challenge_id'))->toBeString()
        ->and($response->json('options.challenge'))->toBeString();
});

it('makes challenge records single-use and bound to ceremony and user', function (): void {
    $store = app(PasskeyChallengeStore::class);
    $first = $store->issue('registration', 'first-options', 411);
    $second = $store->issue('authentication', 'second-options');

    expect($store->consume($first, 'registration', 411))->toBe('first-options')
        ->and(fn () => $store->consume($first, 'registration', 411))
        ->toThrow(ValidationException::class, 'Passkey request expired')
        ->and(fn () => $store->consume($second, 'registration'))
        ->toThrow(ValidationException::class, 'Passkey request expired');
});

it('lists only the authenticated users passkeys and blocks foreign deletion', function (): void {
    $owner = User::factory()->create([
        'name' => 'Mobile list owner',
        'email' => 'mobile-list-owner@kraite.test',
    ]);
    $intruder = User::factory()->create([
        'name' => 'Mobile list intruder',
        'email' => 'mobile-list-intruder@kraite.test',
    ]);
    $ownersPasskey = $owner->passkeys()->create([
        'name' => 'Owner iPhone',
        'credential_id' => 'owner-credential-id',
        'credential' => [],
    ]);
    $intrudersPasskey = $intruder->passkeys()->create([
        'name' => 'Intruder iPhone',
        'credential_id' => 'intruder-credential-id',
        'credential' => [],
    ]);

    Sanctum::actingAs($intruder, ['dashboard:read']);
    $this->deleteJson(passkeyApiUrl('/v1/passkeys/'.$ownersPasskey->id))->assertForbidden();
    $this->assertDatabaseHas('passkeys', ['id' => $ownersPasskey->id, 'name' => 'Owner iPhone']);

    Sanctum::actingAs($owner, ['dashboard:read']);
    $this->getJson(passkeyApiUrl('/v1/passkeys'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownersPasskey->id)
        ->assertJsonPath('data.0.name', 'Owner iPhone')
        ->assertJsonMissing(['name' => 'Intruder iPhone']);

    $this->deleteJson(passkeyApiUrl('/v1/passkeys/'.$ownersPasskey->id))->assertNoContent();
    $this->assertDatabaseMissing('passkeys', ['id' => $ownersPasskey->id]);
    $this->assertDatabaseHas('passkeys', ['id' => $intrudersPasskey->id, 'name' => 'Intruder iPhone']);
});

it('issues a read-only mobile token after a verified passkey assertion', function (): void {
    $user = User::factory()->create([
        'name' => 'Mobile verified passkey trader',
        'email' => 'mobile-verified-passkey@kraite.test',
    ]);
    $passkey = $user->passkeys()->create([
        'name' => 'Verified iPhone',
        'credential_id' => 'verified-credential-id',
        'credential' => [],
    ]);
    $passkey->setRelation('user', $user);
    $options = app(GenerateVerificationOptions::class)();
    $challengeId = app(PasskeyChallengeStore::class)->issue(
        'authentication',
        WebAuthn::toJson($options),
    );
    $verify = Mockery::mock(VerifyPasskey::class);
    $verify->shouldReceive('__invoke')->once()->andReturn($passkey);
    app()->instance(VerifyPasskey::class, $verify);

    $this->postJson(passkeyApiUrl('/v1/auth/passkey/token'), [
        'challenge_id' => $challengeId,
        'credential' => passkeyAssertionCredential(),
        'device_name' => 'Face ID iPhone',
    ])->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('passkeys_enabled', true)
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonMissingPath('user.password');

    $token = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->sole();
    expect($token->name)->toBe('Face ID iPhone')
        ->and(json_decode($token->abilities, true, flags: JSON_THROW_ON_ERROR))->toBe(['dashboard:read', 'accounts:write']);

    $this->postJson(passkeyApiUrl('/v1/auth/passkey/token'), [
        'challenge_id' => $challengeId,
        'credential' => passkeyAssertionCredential(),
        'device_name' => 'Replay iPhone',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('credential');

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(1);
});

it('refuses to issue a trader token after a sysadmin passkey assertion', function (): void {
    $admin = User::factory()->create([
        'name' => 'Mobile passkey admin',
        'email' => 'mobile-passkey-admin@kraite.test',
        'is_admin' => true,
    ]);
    $passkey = $admin->passkeys()->create([
        'name' => 'Admin iPhone',
        'credential_id' => 'admin-credential-id',
        'credential' => [],
    ]);
    $passkey->setRelation('user', $admin);
    $options = app(GenerateVerificationOptions::class)();
    $challengeId = app(PasskeyChallengeStore::class)->issue(
        'authentication',
        WebAuthn::toJson($options),
    );
    $verify = Mockery::mock(VerifyPasskey::class);
    $verify->shouldReceive('__invoke')->once()->andReturn($passkey);
    app()->instance(VerifyPasskey::class, $verify);

    $this->postJson(passkeyApiUrl('/v1/auth/passkey/token'), [
        'challenge_id' => $challengeId,
        'credential' => passkeyAssertionCredential(),
        'device_name' => 'Admin Face ID iPhone',
    ])->assertForbidden();

    expect(DB::table('personal_access_tokens')
        ->where('tokenable_type', $admin->getMorphClass())
        ->where('tokenable_id', $admin->id)
        ->exists())->toBeFalse();
});

it('rejects malformed passkey payloads as validation errors', function (): void {
    $this->postJson(passkeyApiUrl('/v1/auth/passkey/token'), [
        'challenge_id' => fake()->uuid(),
        'credential' => [
            'id' => 'malformed-credential',
            'rawId' => 'malformed-credential',
            'type' => 'public-key',
            'response' => ['clientDataJSON' => 'not-base64url'],
        ],
        'device_name' => 'Malformed iPhone',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.credential.0', 'Invalid passkey response.');

    expect(DB::table('personal_access_tokens')->where('name', 'Malformed iPhone')->exists())->toBeFalse();
});
