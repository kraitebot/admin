<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;

abstract class PasskeyCredentialRequest extends FormRequest
{
    private PublicKeyCredential $passkeyCredential;

    public function credential(): PublicKeyCredential
    {
        return $this->passkeyCredential;
    }

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
}
