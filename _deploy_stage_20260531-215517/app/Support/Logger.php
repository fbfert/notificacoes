<?php

declare(strict_types=1);

namespace App\Support;

final class Logger
{
    public static function security(string $message, array $context = []): void
    {
        self::write('security', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    private static function write(string $channel, string $message, array $context = []): void
    {
        $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $file = $logDir . DIRECTORY_SEPARATOR . $channel . '.log';
        $payload = [
            'ts' => date('Y-m-d H:i:s'),
            'channel' => $channel,
            'message' => $message,
            'context' => $context,
        ];

        @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
