<?php

declare(strict_types=1);

namespace App\Support\Registration;

final class PasswordStrength
{
    public const THRESHOLD = 70;

    public static function passes(string $password): bool
    {
        return self::score($password) >= self::THRESHOLD;
    }

    public static function score(string $password): int
    {
        if ($password === '') {
            return 0;
        }

        $score = min(40, strlen($password) * 4);
        $score += preg_match('/[a-z]/', $password) === 1 ? 10 : 0;
        $score += preg_match('/[A-Z]/', $password) === 1 ? 10 : 0;
        $score += preg_match('/[0-9]/', $password) === 1 ? 10 : 0;
        $score += preg_match('/[^a-zA-Z0-9]/', $password) === 1 ? 10 : 0;
        $score += min(15, count(array_unique(str_split($password))) * 1.5);

        if (preg_match('/(.)\1{2,}/', $password) === 1) {
            $score -= 15;
        }

        if (in_array(strtolower($password), ['password', 'password1', '12345678', 'qwerty', 'letmein', 'admin', 'welcome'], true)) {
            $score -= 20;
        }

        return max(0, min(100, (int) round($score)));
    }
}
