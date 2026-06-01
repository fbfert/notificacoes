<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    public static function appEnv(): string
    {
        return self::string('APP_ENV', 'local');
    }

    public static function appDebug(): bool
    {
        return self::bool('APP_DEBUG', false);
    }

    public static function appUrl(): string
    {
        return self::string('APP_URL', 'http://localhost');
    }

    public static function smsDriver(): string
    {
        return self::string('SMS_DRIVER', 'mock');
    }

    public static function smsProvider(): string
    {
        return self::string('SMS_PROVIDER', 'mock');
    }

    public static function smsAllowRealSend(): bool
    {
        return self::bool('SMS_ALLOW_REAL_SEND', false);
    }

    public static function smsTestOnly(): bool
    {
        return self::bool('SMS_TEST_ONLY', false);
    }

    /**
     * @return string[]
     */
    public static function smsAllowedTestPhones(): array
    {
        $raw = (string) self::string('SMS_ALLOWED_TEST_PHONES', '');
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/\s*[;,]\s*/', $raw) ?: [];
        $phones = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            try {
                $phones[] = PhoneNormalizer::normalizeBrazilian($part);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return array_values(array_unique($phones));
    }

    public static function queueBatchSize(): int
    {
        return max(1, self::int('QUEUE_BATCH_SIZE', 20));
    }

    public static function queueMaxAttempts(): int
    {
        return max(1, self::int('QUEUE_MAX_ATTEMPTS', 3));
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = Env::get($key, $default);
        if ($value === null) {
            return $default;
        }

        return trim((string) $value);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = Env::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = Env::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }
}
