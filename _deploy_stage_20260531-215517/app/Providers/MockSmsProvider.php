<?php

declare(strict_types=1);

namespace App\Providers;

final class MockSmsProvider implements SmsProviderInterface
{
    public function send(string $phone, string $message, array $context = []): array
    {
        $providerMessageId = 'mock_' . date('YmdHis') . '_' . random_int(1000, 9999);

        return [
            'success' => true,
            'provider' => 'mock',
            'provider_message_id' => $providerMessageId,
            'external_id' => $providerMessageId,
            'phone' => $phone,
            'message' => $message,
            'context' => $context,
        ];
    }
}
