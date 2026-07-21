<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePasskeyRequest;
use App\Http\Requests\Api\V1\VerifyPasskeyRequest;
use App\Models\User;
use App\Services\MobileTokenIssuer;
use App\Services\PasskeyChallengeStore;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

class PasskeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $user->passkeys()
                ->latest()
                ->get()
                ->map(fn (Passkey $passkey): array => [
                    'id' => (int) $passkey->id,
                    'name' => $passkey->name,
                    'authenticator' => $passkey->authenticator,
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                    'created_at' => $passkey->created_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    public function registrationOptions(
        Request $request,
        GenerateRegistrationOptions $generate,
        PasskeyChallengeStore $challenges,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $options = $generate($user);

        return response()->json([
            'challenge_id' => $challenges->issue(
                ceremony: 'registration',
                options: WebAuthn::toJson($options),
                userId: (int) $user->id,
            ),
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    public function store(
        StorePasskeyRequest $request,
        StorePasskey $store,
        PasskeyChallengeStore $challenges,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $serializedOptions = $challenges->consume(
            challengeId: $request->string('challenge_id')->toString(),
            ceremony: 'registration',
            userId: (int) $user->id,
        );
        $options = WebAuthn::fromJson($serializedOptions, PublicKeyCredentialCreationOptions::class);
        $passkey = $store(
            $user,
            $request->string('name')->trim()->toString(),
            $request->credential(),
            $options,
        );

        return response()->json([
            'data' => [
                'id' => (int) $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'last_used_at' => null,
                'created_at' => $passkey->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function destroy(Request $request, Passkey $passkey, DeletePasskey $delete): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless((int) $passkey->user_id === (int) $user->id, 403);

        $delete($user, $passkey);

        return response()->json(status: 204);
    }

    public function authenticationOptions(
        GenerateVerificationOptions $generate,
        PasskeyChallengeStore $challenges,
    ): JsonResponse {
        $options = $generate();

        return response()->json([
            'challenge_id' => $challenges->issue(
                ceremony: 'authentication',
                options: WebAuthn::toJson($options),
            ),
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    public function authenticate(
        VerifyPasskeyRequest $request,
        VerifyPasskey $verify,
        PasskeyChallengeStore $challenges,
        MobileTokenIssuer $tokens,
    ): JsonResponse {
        $serializedOptions = $challenges->consume(
            challengeId: $request->string('challenge_id')->toString(),
            ceremony: 'authentication',
        );
        $options = WebAuthn::fromJson($serializedOptions, PublicKeyCredentialRequestOptions::class);
        $passkey = $verify($request->credential(), $options);

        if (! Passkeys::allowsLogin($request, $passkey) || ! ($passkey->user instanceof User)) {
            throw new AuthenticationException('Unable to sign in with this passkey.');
        }

        return response()->json($tokens->issue(
            $passkey->user,
            $request->string('device_name')->trim()->toString() ?: 'Kraite iPhone',
        ));
    }
}
