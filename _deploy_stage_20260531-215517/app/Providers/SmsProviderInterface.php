<?php

declare(strict_types=1);

namespace App\Providers;

interface SmsProviderInterface
{
    public function send(string $phone, string $message, array $context = []): array;
}
