<?php

declare(strict_types=1);

namespace App\Support;

use App\Providers\MockSmsProvider;
use App\Providers\SmsProviderInterface;

final class SmsProviderResolver
{
    public function resolve(): SmsProviderInterface
    {
        $driver = strtolower(Config::smsDriver());
        $provider = strtolower(Config::smsProvider());
        $allowReal = Config::smsAllowRealSend();

        if (!$allowReal) {
            if ($driver !== '' && $driver !== 'mock') {
                Logger::security('SMS_DRIVER nao mock em modo fail-closed; fallback para mock', [
                    'sms_driver' => $driver,
                    'sms_provider' => $provider,
                    'sms_allow_real_send' => $allowReal,
                ]);
            }

            if ($provider !== '' && $provider !== 'mock') {
                Logger::security('SMS_PROVIDER nao mock em modo fail-closed; fallback para mock', [
                    'sms_driver' => $driver,
                    'sms_provider' => $provider,
                    'sms_allow_real_send' => $allowReal,
                ]);
            }

            return new MockSmsProvider();
        }

        if ($driver === '' || $driver === 'mock') {
            return new MockSmsProvider();
        }

        Logger::security('SMS_ALLOW_REAL_SEND verdadeiro mas suporte real nao implementado; fallback para mock', [
            'sms_driver' => $driver,
            'sms_provider' => $provider,
            'sms_allow_real_send' => $allowReal,
        ]);

        return new MockSmsProvider();
    }

    public function describeSecurityMode(): array
    {
        return [
            'sms_driver' => Config::smsDriver(),
            'sms_provider' => Config::smsProvider(),
            'sms_allow_real_send' => Config::smsAllowRealSend(),
            'resolved_provider' => MockSmsProvider::class,
        ];
    }
}
