<?php

declare(strict_types=1);

require_once __DIR__ . '/TarsNotificationsClient.php';

$baseUrl = getenv('TARS_NOTIFICACOES_BASE_URL') ?: 'https://gateway.tars.art.br';
$apiKey = getenv('TARS_NOTIFICACOES_API_KEY') ?: '';
$testPhone = getenv('TARS_NOTIFICACOES_TEST_PHONE') ?: '5549999999999';
$timeout = (int) (getenv('TARS_NOTIFICACOES_TIMEOUT') ?: 10);

if ($apiKey === '') {
    fwrite(STDERR, "TARS_NOTIFICACOES_API_KEY nao informado.\n");
    exit(1);
}

$client = new TarsNotificationsClient($baseUrl, $apiKey, $timeout);
$result = $client->send(
    $testPhone,
    'Teste de integracao do projeto cliente com o Tars Notificacoes.',
    'test',
    'kit-' . date('YmdHis') . '-' . bin2hex(random_bytes(4))
);

echo 'HTTP: ' . $result['http_status'] . PHP_EOL;
echo 'Gateway status: ' . ($result['gateway_status'] ?? 'n/a') . PHP_EOL;
echo 'Message ID: ' . ($result['message_id'] ?? 'n/a') . PHP_EOL;
echo 'OK: ' . (($result['ok'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo 'Error: ' . ($result['error'] ?? 'none') . PHP_EOL;

if (is_array($result['response'])) {
    echo json_encode($result['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo (string) $result['response'] . PHP_EOL;
}
