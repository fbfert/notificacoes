<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Support/Env.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

final class SecurityTestRunner
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): int
    {
        $this->testTestOnlyAllowedPhone();
        $this->testTestOnlyDisallowedPhone();
        $this->testTestOnlyEmptyAllowlist();
        $this->testResolverMockMode();
        $this->testResolverExternalBlocked();
        $this->testResolverInvalidDriver();
        $this->testAllowedPhonesNormalization();

        $total = $this->passed + $this->failed;
        echo sprintf("[%s] Total: %d | Aprovados: %d | Falhas: %d\n", date('Y-m-d H:i:s'), $total, $this->passed, $this->failed);

        return $this->failed === 0 ? 0 : 1;
    }

    private function testTestOnlyAllowedPhone(): void
    {
        $this->withEnv([
            'SMS_TEST_ONLY' => 'true',
            'SMS_ALLOWED_TEST_PHONES' => '(49) 99999-9999',
        ], function (): void {
            $result = \App\Services\SmsService::evaluateRecipientForSending('5549999999999');
            $this->assertTrue($result['allowed'] === true, 'SMS_TEST_ONLY aceita numero permitido');
            $this->assertTrue($result['phone'] === '5549999999999', 'Numero permitido e normalizado para 55');
        });
    }

    private function testTestOnlyDisallowedPhone(): void
    {
        $this->withEnv([
            'SMS_TEST_ONLY' => 'true',
            'SMS_ALLOWED_TEST_PHONES' => '(49) 99999-9999',
        ], function (): void {
            $result = \App\Services\SmsService::evaluateRecipientForSending('5511912345678');
            $this->assertTrue($result['allowed'] === false, 'SMS_TEST_ONLY bloqueia numero nao permitido');
            $this->assertTrue($result['block_reason'] === 'test_only_destination_not_allowed', 'Bloqueio por destino fora da lista');
        });
    }

    private function testTestOnlyEmptyAllowlist(): void
    {
        $this->withEnv([
            'SMS_TEST_ONLY' => 'true',
            'SMS_ALLOWED_TEST_PHONES' => '',
        ], function (): void {
            $result = \App\Services\SmsService::evaluateRecipientForSending('5549999999999');
            $this->assertTrue($result['allowed'] === false, 'SMS_TEST_ONLY bloqueia com lista vazia');
            $this->assertTrue($result['block_reason'] === 'test_only_allowlist_empty', 'Bloqueio por lista de teste vazia');
        });
    }

    private function testResolverMockMode(): void
    {
        $this->withEnv([
            'SMS_DRIVER' => 'mock',
            'SMS_PROVIDER' => 'mock',
            'SMS_ALLOW_REAL_SEND' => 'false',
        ], function (): void {
            $provider = (new \App\Support\SmsProviderResolver())->resolve();
            $this->assertTrue($provider instanceof \App\Providers\MockSmsProvider, 'Modo mock retorna MockSmsProvider');
        });
    }

    private function testResolverExternalBlocked(): void
    {
        $this->withEnv([
            'SMS_DRIVER' => 'external',
            'SMS_PROVIDER' => 'external',
            'SMS_ALLOW_REAL_SEND' => 'false',
        ], function (): void {
            $provider = (new \App\Support\SmsProviderResolver())->resolve();
            $this->assertTrue($provider instanceof \App\Providers\MockSmsProvider, 'Modo fail-closed bloqueia provider externo');
        });
    }

    private function testResolverInvalidDriver(): void
    {
        $this->withEnv([
            'SMS_DRIVER' => 'banana',
            'SMS_PROVIDER' => 'banana',
            'SMS_ALLOW_REAL_SEND' => 'false',
        ], function (): void {
            $provider = (new \App\Support\SmsProviderResolver())->resolve();
            $this->assertTrue($provider instanceof \App\Providers\MockSmsProvider, 'Driver invalido permanece em mock');
        });
    }

    private function testAllowedPhonesNormalization(): void
    {
        $this->withEnv([
            'SMS_ALLOWED_TEST_PHONES' => '(49) 99999-9999, +55 (49) 99999-9999; 55 49 99999-9999',
        ], function (): void {
            $phones = \App\Support\Config::smsAllowedTestPhones();
            $this->assertTrue($phones === ['5549999999999'], 'Lista de teste e normalizada e deduplicada');
        });
    }

    private function withEnv(array $vars, callable $callback): void
    {
        $backup = [];
        foreach ($vars as $key => $value) {
            $backup[$key] = array_key_exists($key, $_ENV) ? $_ENV[$key] : null;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }

        try {
            $callback();
        } finally {
            foreach (array_keys($vars) as $key) {
                if ($backup[$key] === null) {
                    unset($_ENV[$key]);
                    putenv($key);
                    continue;
                }

                $_ENV[$key] = $backup[$key];
                putenv($key . '=' . $backup[$key]);
            }
        }
    }

    private function assertTrue(bool $condition, string $label): void
    {
        if ($condition) {
            $this->passed++;
            echo "[OK] {$label}\n";
            return;
        }

        $this->failed++;
        echo "[FAIL] {$label}\n";
    }
}

exit((new SecurityTestRunner())->run());
