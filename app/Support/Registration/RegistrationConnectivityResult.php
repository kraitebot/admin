<?php

declare(strict_types=1);

namespace App\Support\Registration;

class RegistrationConnectivityResult
{
    public function __construct(
        public readonly bool $connected,
        public readonly string $message,
        public readonly ?int $ordersCount = null,
    ) {}
}
