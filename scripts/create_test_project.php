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

$name = 'Projeto Teste v0.2';
$slug = 'projeto-teste-v0-2';
$apiKeyPlain = bin2hex(random_bytes(24));
$apiKeyHash = password_hash($apiKeyPlain, PASSWORD_DEFAULT);

$existing = Database::fetchOne(
    'SELECT id FROM tn_projects WHERE slug = :slug LIMIT 1',
    [':slug' => $slug]
);

if ($existing !== null) {
    fwrite(STDERR, "Slug ja existe: {$slug}\n");
    exit(1);
}

Database::insert(
    'INSERT INTO tn_projects
        (name, slug, api_key_hash, active, daily_limit, monthly_limit, max_attempts, created_at, updated_at)
     VALUES
        (:name, :slug, :api_key_hash, 1, NULL, NULL, 3, NOW(), NOW())',
    [
        ':name' => $name,
        ':slug' => $slug,
        ':api_key_hash' => $apiKeyHash,
    ]
);

echo "Projeto criado com sucesso\n";
echo "Nome: {$name}\n";
echo "Slug: {$slug}\n";
echo "API key gerada agora, exibida uma unica vez:\n";
echo $apiKeyPlain . "\n";
echo "A chave nao sera exibida novamente e foi salva apenas como hash no banco.\n";
