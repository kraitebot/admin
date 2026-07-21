<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasskeyChallengeStore
{
    private const PREFIX = 'passkeys:mobile:';

    public function issue(string $ceremony, string $options, ?int $userId = null): string
    {
        $challengeId = (string) Str::uuid();

        Cache::put(self::PREFIX.$challengeId, [
            'ceremony' => $ceremony,
            'options' => $options,
            'user_id' => $userId,
        ], now()->addMinutes(2));

        return $challengeId;
    }

    public function consume(string $challengeId, string $ceremony, ?int $userId = null): string
    {
        $lock = Cache::lock(self::PREFIX.'lock:'.$challengeId, 5);

        if (! $lock->get()) {
            $this->throwExpired();
        }

        try {
            $challenge = Cache::pull(self::PREFIX.$challengeId);
        } finally {
            $lock->release();
        }

        if (! is_array($challenge)
            || ($challenge['ceremony'] ?? null) !== $ceremony
            || ($challenge['user_id'] ?? null) !== $userId
            || ! is_string($challenge['options'] ?? null)) {
            $this->throwExpired();
        }

        return $challenge['options'];
    }

    private function throwExpired(): never
    {
        throw ValidationException::withMessages([
            'credential' => ['Passkey request expired. Please try again.'],
        ]);
    }
}
