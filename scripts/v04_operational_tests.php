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

\App\Support\Env::load(BASE_PATH . '/.env');
date_default_timezone_set((string) ($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo'));

use App\Core\Database;
use App\Support\Config;
use App\Support\PhoneNormalizer;

if (!function_exists('shell_exec')) {
    fwrite(STDERR, "shell_exec indisponivel.\n");
    exit(1);
}

if (trim((string) shell_exec('curl --version 2>&1')) === '') {
    fwrite(STDERR, "Curl CLI nao disponivel.\n");
    exit(1);
}

final class OperationalTestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private string $baseUrl;
    private string $adminPassword;
    private string $phone;
    private string $apiKeyOld;
    private string $projectSlug;
    private int $projectId;
    private string $cookieJar;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) (getenv('BASE_URL') ?: Config::appUrl() ?: 'https://gateway.tars.art.br'), '/');
        $this->adminPassword = (string) (getenv('ADMIN_PASSWORD') ?: ($_ENV['ADMIN_PASSWORD'] ?? ''));
        $allowedPhones = Config::smsAllowedTestPhones();
        $fallbackPhone = (string) (getenv('PHONE_VALIDO') ?: '5549999999999');
        $candidate = $allowedPhones[0] ?? $fallbackPhone;

        try {
            $this->phone = PhoneNormalizer::normalizeBrazilian($candidate);
        } catch (\Throwable) {
            $this->phone = '5549999999999';
        }

        $this->apiKeyOld = bin2hex(random_bytes(24));
        $this->projectSlug = 'operational-v04-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'tncookies_') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tncookies_' . uniqid();
    }

    public function run(): int
    {
        try {
            if (!$this->schemaReady()) {
                echo sprintf("[%s] Total: %d | Aprovados: %d | Falhas: %d\n", date('Y-m-d H:i:s'), 1, 0, 1);
                return 1;
            }

            $this->createProject();
            $this->loginAdmin();
            $this->testHealth();
            $firstMessageId = $this->testSendWithOldKey();
            $this->testStatusEndpoint($firstMessageId, $this->apiKeyOld, 'status com chave original');

            [$newKey, $regenHtml] = $this->regenerateApiKey();
            $this->assertTrue($newKey !== '' && $newKey !== $this->apiKeyOld, 'Nova API key exibida uma unica vez');
            $this->assertTrue(str_contains($regenHtml, 'Nova API key gerada'), 'Painel exibiu a chave regenerada');
            $this->assertTrue(!str_contains($regenHtml, $this->apiKeyOld), 'Painel nao expôs a chave antiga');

            $this->testOldKeyFailsAfterRotation();
            $secondMessageId = $this->testSendWithNewKey($newKey);
            $this->assertTrue($secondMessageId !== $firstMessageId, 'Nova mensagem criada com chave nova');
            $this->testIdempotentReplay($newKey, $secondMessageId);
            $this->testStatusEndpoint($secondMessageId, $newKey, 'status com chave nova');
            $this->testForeignProjectStatusReturns404($newKey);
            $this->testPanelFilters($secondMessageId);
            $this->testMessageDetail($secondMessageId);
            $this->testMinuteLimit($newKey);
            $this->cleanup();

            $total = $this->passed + $this->failed;
            echo sprintf("[%s] Total: %d | Aprovados: %d | Falhas: %d\n", date('Y-m-d H:i:s'), $total, $this->passed, $this->failed);

            return $this->failed === 0 ? 0 : 1;
        } finally {
            if (is_file($this->cookieJar)) {
                @unlink($this->cookieJar);
            }
            if (isset($this->projectId)) {
                $this->cleanup();
            }
        }
    }

    private function createProject(): void
    {
        $hash = password_hash($this->apiKeyOld, PASSWORD_DEFAULT);
        $this->projectId = (int) Database::insert(
            'INSERT INTO tn_projects
                (name, slug, api_key_hash, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at, created_at, updated_at)
             VALUES
                (:name, :slug, :api_key_hash, 1, NULL, NULL, NULL, 3, NULL, NOW(), NOW())',
            [
                ':name' => 'Projeto Operacional v0.4',
                ':slug' => $this->projectSlug,
                ':api_key_hash' => $hash,
            ]
        );

        $this->assertTrue($this->projectId > 0, 'Projeto operacional criado no banco');
    }

    private function schemaReady(): bool
    {
        $checks = [
            'tn_projects' => ['minute_limit', 'last_used_at'],
            'tn_sms_messages' => ['delivered_at', 'failed_at'],
        ];

        foreach ($checks as $table => $columns) {
            foreach ($columns as $column) {
                $row = Database::fetchOne(
                    'SELECT COUNT(*) AS total
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table
                       AND COLUMN_NAME = :column',
                    [
                        ':table' => $table,
                        ':column' => $column,
                    ]
                );

                if ((int) ($row['total'] ?? 0) !== 1) {
                    $this->assertTrue(false, sprintf('Schema incompleto: execute os ALTER TABLE para adicionar %s.%s', $table, $column));
                    return false;
                }
            }
        }

        return true;
    }

    private function loginAdmin(): void
    {
        $this->assertTrue($this->adminPassword !== '', 'ADMIN_PASSWORD informado');

        [$status, $body] = $this->request('GET', $this->baseUrl . '/admin');
        $this->assertTrue($status === 200, 'Abertura do painel admin');

        $csrf = $this->extractCsrf($body);
        $this->assertTrue($csrf !== '', 'CSRF obtido do login');

        [$loginStatus] = $this->request('POST', $this->baseUrl . '/admin/login', [
            'csrf_token' => $csrf,
            'password' => $this->adminPassword,
        ], [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $this->assertTrue($loginStatus === 302, 'Login administrativo aceito');
    }

    private function testHealth(): void
    {
        [$status, $body] = $this->request('GET', $this->baseUrl . '/health');
        $this->assertTrue($status === 200, '/health respondeu HTTP 200');

        $payload = json_decode($body, true);
        $this->assertTrue(is_array($payload), '/health retornou JSON');
        $this->assertTrue(($payload['success'] ?? false) === true, '/health success=true');
        $this->assertTrue(($payload['allow_real_send'] ?? null) === false, '/health allow_real_send=false');
        $this->assertTrue(isset($payload['queue_status']) && is_array($payload['queue_status']), '/health queue_status presente');
        $this->assertTrue(!str_contains($body, $this->apiKeyOld), '/health nao expôs API key');
    }

    private function testSendWithOldKey(): int
    {
        $idempotency = 'op-old-' . date('YmdHis') . '-' . random_int(1000, 9999);
        [$status, $body] = $this->sendSms($this->apiKeyOld, $this->phone, 'Mensagem operacional v0.4', 'transactional', $idempotency);
        $this->assertTrue($status === 202, 'Envio inicial com chave antiga retornou 202');

        $payload = json_decode($body, true);
        $messageId = (int) ($payload['data']['message_id'] ?? 0);
        $this->assertTrue($messageId > 0, 'Envio inicial retornou message_id');

        return $messageId;
    }

    private function testOldKeyFailsAfterRotation(): void
    {
        [$status, $body] = $this->sendSms($this->apiKeyOld, $this->phone, 'Mensagem apos rotacao', 'transactional', 'op-old-fail-' . random_int(1000, 9999));
        $this->assertTrue($status === 401, 'Chave antiga falhou apos regeneracao');
        $this->assertTrue(str_contains($body, 'API key invalida') || str_contains($body, 'API key ausente'), 'Resposta da chave antiga foi segura');
    }

    private function testSendWithNewKey(string $newKey): int
    {
        $idempotency = 'op-new-' . date('YmdHis') . '-' . random_int(1000, 9999);
        [$status, $body] = $this->sendSms($newKey, $this->phone, 'Mensagem operacional com chave nova', 'transactional', $idempotency);
        $this->assertTrue($status === 202, 'Envio com chave nova retornou 202');

        $payload = json_decode($body, true);
        $messageId = (int) ($payload['data']['message_id'] ?? 0);
        $this->assertTrue($messageId > 0, 'Envio com chave nova retornou message_id');

        return $messageId;
    }

    private function testIdempotentReplay(string $apiKey, int $messageId): void
    {
        $idempotency = 'op-replay-' . $messageId;
        [$firstStatus, $firstBody] = $this->sendSms($apiKey, $this->phone, 'Mensagem idempotente', 'transactional', $idempotency);
        $this->assertTrue($firstStatus === 202, 'Primeiro envio idempotente aceito');
        $first = json_decode($firstBody, true);
        $firstId = (int) ($first['data']['message_id'] ?? 0);

        [$secondStatus, $secondBody] = $this->sendSms($apiKey, $this->phone, 'Mensagem idempotente', 'transactional', $idempotency);
        $this->assertTrue($secondStatus === 200, 'Reenvio idempotente retornou 200');
        $second = json_decode($secondBody, true);
        $secondId = (int) ($second['data']['message_id'] ?? 0);
        $this->assertTrue($firstId > 0 && $firstId === $secondId, 'Reenvio idempotente retornou o mesmo message_id');
    }

    private function testStatusEndpoint(int $messageId, string $apiKey, string $label): void
    {
        [$status, $body] = $this->request('GET', $this->baseUrl . '/api/sms/status/' . $messageId, null, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);

        $this->assertTrue($status === 200, $label . ' retornou HTTP 200');
        $payload = json_decode($body, true);
        $this->assertTrue(is_array($payload) && ($payload['success'] ?? false) === true, $label . ' retornou sucesso');
        $data = $payload['data'] ?? [];
        $this->assertTrue((int) ($data['message_id'] ?? 0) === $messageId, $label . ' message_id confere');
        $this->assertTrue(in_array((string) ($data['type'] ?? ''), Config::allowedSmsTypes(), true), $label . ' type valido');
        $this->assertTrue(isset($data['status']), $label . ' status presente');
    }

    private function testPanelFilters(int $messageId): void
    {
        [$status, $body] = $this->request('GET', $this->baseUrl . '/admin/messages?message_id=' . $messageId . '&project=' . urlencode($this->projectSlug));
        $this->assertTrue($status === 200, 'Filtros do painel responderam HTTP 200');
        $this->assertTrue(str_contains($body, (string) $messageId), 'Filtros do painel exibiram message_id');
        $this->assertTrue(str_contains($body, $this->projectSlug), 'Filtros do painel exibiram o projeto');
    }

    private function testForeignProjectStatusReturns404(string $apiKey): void
    {
        $foreignSlug = $this->projectSlug . '-foreign';
        $foreignKey = bin2hex(random_bytes(24));
        $foreignHash = password_hash($foreignKey, PASSWORD_DEFAULT);
        $foreignProjectId = (int) Database::insert(
            'INSERT INTO tn_projects
                (name, slug, api_key_hash, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at, created_at, updated_at)
             VALUES
                (:name, :slug, :api_key_hash, 1, NULL, NULL, NULL, 3, NULL, NOW(), NOW())',
            [
                ':name' => 'Projeto Operacional Externo',
                ':slug' => $foreignSlug,
                ':api_key_hash' => $foreignHash,
            ]
        );

        $foreignMessageId = 0;
        try {
            [$status, $body] = $this->sendSms($foreignKey, $this->phone, 'Mensagem de outro projeto', 'transactional', 'foreign-' . random_int(1000, 9999));
            $this->assertTrue($status === 202, 'Projeto estrangeiro aceito');
            $payload = json_decode($body, true);
            $foreignMessageId = (int) ($payload['data']['message_id'] ?? 0);
            $this->assertTrue($foreignMessageId > 0, 'Projeto estrangeiro gerou message_id');

            [$status404, $body404] = $this->request('GET', $this->baseUrl . '/api/sms/status/' . $foreignMessageId, null, [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ]);
            $this->assertTrue($status404 === 404, 'Mensagem de outro projeto retorna 404');
            $this->assertTrue(str_contains($body404, 'Mensagem nao encontrada'), 'Resposta 404 do status e segura');
        } finally {
            Database::execute('DELETE FROM tn_projects WHERE id = :id', [
                ':id' => $foreignProjectId,
            ]);
        }
    }

    private function testMessageDetail(int $messageId): void
    {
        [$status, $body] = $this->request('GET', $this->baseUrl . '/admin/messages/' . $messageId);
        $this->assertTrue($status === 200, 'Detalhe da mensagem respondeu HTTP 200');
        $this->assertTrue(str_contains($body, 'Timeline de logs'), 'Detalhe da mensagem exibiu timeline');
    }

    private function testMinuteLimit(string $apiKey): void
    {
        Database::execute('DELETE FROM tn_sms_messages WHERE project_id = :id', [
            ':id' => $this->projectId,
        ]);

        Database::execute(
            'UPDATE tn_projects
             SET minute_limit = 1,
                 updated_at = NOW()
             WHERE id = :id',
            [
                ':id' => $this->projectId,
            ]
        );

        $idempotency1 = 'minute-1-' . random_int(1000, 9999);
        [$firstStatus] = $this->sendSms($apiKey, $this->phone, 'Mensagem minuto 1', 'transactional', $idempotency1);
        $this->assertTrue($firstStatus === 202, 'Primeiro envio no minuto foi aceito');

        $idempotency2 = 'minute-2-' . random_int(1000, 9999);
        [$secondStatus, $secondBody] = $this->sendSms($apiKey, $this->phone, 'Mensagem minuto 2', 'transactional', $idempotency2);
        $this->assertTrue($secondStatus === 422, 'minute_limit bloqueou envio excedente');
        $this->assertTrue(str_contains($secondBody, 'Limite por minuto do projeto atingido'), 'minute_limit retornou mensagem clara');
    }

    private function regenerateApiKey(): array
    {
        [$status, $body] = $this->request('GET', $this->baseUrl . '/admin/projects');
        $this->assertTrue($status === 200, 'Lista de projetos acessada');
        $csrf = $this->extractCsrf($body);
        $this->assertTrue($csrf !== '', 'CSRF obtido para gestao de chaves');

        [$regenStatus, $regenBody] = $this->request('POST', $this->baseUrl . '/admin/projects/' . $this->projectId . '/regenerate-key', [
            'csrf_token' => $csrf,
        ], [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $this->assertTrue($regenStatus === 200 || $regenStatus === 302, 'Regeneracao de API key processada');

        $html = $regenBody;
        if ($regenStatus === 302) {
            [$finalStatus, $finalBody] = $this->request('GET', $this->baseUrl . '/admin/projects');
            $this->assertTrue($finalStatus === 200, 'Redirecionamento da regeneracao finalizado');
            $html = $finalBody;
        }

        $newKey = $this->extractSecretKey($html);
        return [$newKey, $html];
    }

    private function cleanup(): void
    {
        if (!isset($this->projectId)) {
            return;
        }

        Database::execute('DELETE FROM tn_projects WHERE id = :id', [
            ':id' => $this->projectId,
        ]);
    }

    /**
     * @return array{0:int,1:string}
     */
    /**
     * @param array<string,mixed>|string|null $body
     * @return array{0:int,1:string}
     */
    private function request(string $method, string $url, array|string|null $body = null, array $headers = [], bool $followRedirects = false): array
    {
        $responseFile = tempnam(sys_get_temp_dir(), 'tnresp_') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tnresp_' . uniqid();
        $errorFile = tempnam(sys_get_temp_dir(), 'tnerr_') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tnerr_' . uniqid();
        $payloadFile = null;
        $payload = null;
        if (is_array($body)) {
            $isJson = false;
            foreach ($headers as $header) {
                if (stripos($header, 'Content-Type: application/json') !== false) {
                    $isJson = true;
                    break;
                }
            }

            $payload = $isJson
                ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : http_build_query($body);
        } elseif (is_string($body)) {
            $payload = $body;
        }

        $command = [
            'curl',
            '-sS',
            '-o',
            $responseFile,
            '-w',
            '%{http_code}',
        ];

        if ($followRedirects) {
            $command[] = '-L';
        }

        $command[] = '-X';
        $command[] = strtoupper($method);
        $command[] = '--cookie';
        $command[] = $this->cookieJar;
        $command[] = '--cookie-jar';
        $command[] = $this->cookieJar;

        foreach ($headers as $header) {
            $command[] = '-H';
            $command[] = $header;
        }

        if ($payload !== null) {
            $payloadFile = tempnam(sys_get_temp_dir(), 'tnpay_') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tnpay_' . uniqid();
            file_put_contents($payloadFile, $payload);
            $command[] = '--data-binary';
            $command[] = '@' . $payloadFile;
        }

        $command[] = $url;

        $quoted = array_map(static fn (string $part): string => escapeshellarg($part), $command);
        $stdout = shell_exec(implode(' ', $quoted) . ' 2>' . escapeshellarg($errorFile));
        $status = (int) trim((string) $stdout);
        $responseBody = is_file($responseFile) ? (string) file_get_contents($responseFile) : '';

        if ($payloadFile !== null && is_file($payloadFile)) {
            @unlink($payloadFile);
        }
        if (is_file($errorFile)) {
            @unlink($errorFile);
        }
        if (is_file($responseFile)) {
            @unlink($responseFile);
        }

        if ($status === 0) {
            $this->assertTrue(false, 'Falha de conexao HTTP ao chamar ' . $url);
        }

        return [$status, $responseBody];
    }

    /**
     * @return array{0:int,1:string}
     */
    private function sendSms(string $apiKey, string $phone, string $message, string $type, string $idempotencyKey): array
    {
        return $this->request('POST', $this->baseUrl . '/api/sms/send', [
            'phone' => $phone,
            'message' => $message,
            'type' => $type,
            'idempotency_key' => $idempotencyKey,
        ], [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);
    }

    private function extractCsrf(string $html): string
    {
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches)) {
            return (string) $matches[1];
        }

        return '';
    }

    private function extractSecretKey(string $html): string
    {
        if (preg_match('/<pre class="secret-key">([^<]+)<\/pre>/', $html, $matches)) {
            return html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
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

exit((new OperationalTestRunner())->run());
