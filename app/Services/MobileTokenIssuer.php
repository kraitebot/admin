<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class MobileTokenIssuer
{
    /** @return array{token: string, token_type: string, expires_at: string, passkeys_enabled: bool, user: array{id: int, name: string, email: string}} */
    public function issue(User $user, string $deviceName): array
    {
        $expiresAt = now()->addDays(30);
        $token = $user->createToken(
            trim($deviceName) ?: 'Kraite iPhone',
            ['dashboard:read', 'accounts:write'],
            $expiresAt,
        );

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'passkeys_enabled' => $user->hasPasskeysEnabled(),
            'user' => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ],
        ];
    }
}
