<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;

trait ParsesPasskeyCredential
{
    private PublicKeyCredential $passkeyCredential;

    protected function passedValidation(): void
    {
        try {
            $this->passkeyCredential = WebAuthn::fromJson(
                json_encode($this->input('credential'), JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid passkey response.'],
            ]);
        }
    }

    public function credential(): PublicKeyCredential
    {
        return $this->passkeyCredential;
    }
}
