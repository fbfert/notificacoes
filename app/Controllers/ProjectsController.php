<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Support\Csrf;
use App\Support\Env;

final class ProjectsController
{
    public function index(Request $request): never
    {
        $this->requireAdmin();

        $projects = Database::fetchAll(
            'SELECT id, name, slug, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at, created_at
             FROM tn_projects
             ORDER BY id DESC'
        );

        $flash = $this->consumeFlashMarkup();
        $html = $this->layout('Projetos', $flash . $this->formMarkup() . $this->tableMarkup($projects));

        Response::html($html);
    }

    public function store(Request $request): never
    {
        $this->requireAdmin();

        if (!Csrf::validate((string) $request->input('csrf_token', ''))) {
            Response::html($this->layout('Projetos', '<div class="card">CSRF invalido.</div>' . $this->formMarkup()), 419);
        }

        $name = trim((string) $request->input('name', ''));
        $slug = trim((string) $request->input('slug', ''));
        $apiKeyPlain = trim((string) $request->input('api_key', ''));
        $dailyLimit = $request->input('daily_limit', '');
        $monthlyLimit = $request->input('monthly_limit', '');
        $minuteLimit = $request->input('minute_limit', '');
        $maxAttempts = $request->input('max_attempts', 3);

        if ($name === '') {
            Response::html($this->layout('Projetos', '<div class="card">Nome do projeto obrigatorio.</div>' . $this->formMarkup()), 422);
        }

        if ($apiKeyPlain === '') {
            Response::html($this->layout('Projetos', '<div class="card">API key obrigatoria. Ela sera salva apenas como hash.</div>' . $this->formMarkup()), 422);
        }

        $dailyLimitValue = $dailyLimit === '' ? null : max(0, (int) $dailyLimit);
        $monthlyLimitValue = $monthlyLimit === '' ? null : max(0, (int) $monthlyLimit);
        $minuteLimitValue = $minuteLimit === '' ? null : max(0, (int) $minuteLimit);
        $maxAttemptsValue = max(1, (int) $maxAttempts);

        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? '');
            $slug = trim($slug, '-');
        }

        if ($slug === '') {
            Response::html($this->layout('Projetos', '<div class="card">Nao foi possivel gerar um slug valido.</div>' . $this->formMarkup()), 422);
        }

        $exists = Database::fetchOne('SELECT id FROM tn_projects WHERE slug = :slug LIMIT 1', [':slug' => $slug]);
        if ($exists !== null) {
            Response::html($this->layout('Projetos', '<div class="card">Slug ja cadastrado.</div>' . $this->formMarkup()), 422);
        }

        $hash = password_hash($apiKeyPlain, PASSWORD_DEFAULT);

        Database::insert(
            'INSERT INTO tn_projects
                (name, slug, api_key_hash, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at, created_at, updated_at)
             VALUES
                (:name, :slug, :api_key_hash, 1, :daily_limit, :monthly_limit, :minute_limit, :max_attempts, NULL, NOW(), NOW())',
            [
                ':name' => $name,
                ':slug' => $slug,
                ':api_key_hash' => $hash,
                ':daily_limit' => $dailyLimitValue,
                ':monthly_limit' => $monthlyLimitValue,
                ':minute_limit' => $minuteLimitValue,
                ':max_attempts' => $maxAttemptsValue,
            ]
        );

        $projects = Database::fetchAll(
            'SELECT id, name, slug, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at, created_at
             FROM tn_projects
             ORDER BY id DESC'
        );

        $notice = '<div class="card success">Projeto criado com sucesso. A API key foi armazenada apenas como hash e nao pode ser recuperada depois.</div>';
        $this->flashNotice($notice);

        Response::html($this->layout('Projetos', $this->consumeFlashMarkup() . $this->formMarkup() . $this->tableMarkup($projects)));
    }

    public function regenerateKey(Request $request, string $id): never
    {
        $project = $this->requireAdminProject($request, $id);

        if (!Csrf::validate((string) $request->input('csrf_token', ''))) {
            Response::html($this->layout('Projetos', '<div class="card">CSRF invalido.</div>' . $this->formMarkup()), 419);
        }

        $plainKey = bin2hex(random_bytes(24));
        $hash = password_hash($plainKey, PASSWORD_DEFAULT);

        Database::execute(
            'UPDATE tn_projects
             SET api_key_hash = :api_key_hash,
                 active = 1,
                 updated_at = NOW()
             WHERE id = :id',
            [
                ':api_key_hash' => $hash,
                ':id' => (int) $project['id'],
            ]
        );

        $this->logAdminAction((int) $project['id'], 'admin_project_api_key_regenerated', [
            'project_slug' => $project['slug'],
            'project_name' => $project['name'],
        ]);

        $_SESSION['tn_flash_api_key'] = [
            'project_id' => (int) $project['id'],
            'project_name' => (string) $project['name'],
            'project_slug' => (string) $project['slug'],
            'plain_key' => $plainKey,
        ];

        $_SESSION['tn_flash_notice'] = 'API key regenerada com sucesso. A nova chave sera exibida apenas uma vez.';

        Response::redirect('/admin/projects');
    }

    public function activate(Request $request, string $id): never
    {
        $project = $this->requireAdminProject($request, $id);

        if (!Csrf::validate((string) $request->input('csrf_token', ''))) {
            Response::html($this->layout('Projetos', '<div class="card">CSRF invalido.</div>' . $this->formMarkup()), 419);
        }

        Database::execute(
            'UPDATE tn_projects
             SET active = 1,
                 updated_at = NOW()
             WHERE id = :id',
            [
                ':id' => (int) $project['id'],
            ]
        );

        $this->logAdminAction((int) $project['id'], 'admin_project_activated', [
            'project_slug' => $project['slug'],
            'project_name' => $project['name'],
        ]);

        $_SESSION['tn_flash_notice'] = 'Projeto ativado com sucesso.';

        Response::redirect('/admin/projects');
    }

    public function deactivate(Request $request, string $id): never
    {
        $project = $this->requireAdminProject($request, $id);

        if (!Csrf::validate((string) $request->input('csrf_token', ''))) {
            Response::html($this->layout('Projetos', '<div class="card">CSRF invalido.</div>' . $this->formMarkup()), 419);
        }

        Database::execute(
            'UPDATE tn_projects
             SET active = 0,
                 updated_at = NOW()
             WHERE id = :id',
            [
                ':id' => (int) $project['id'],
            ]
        );

        $this->logAdminAction((int) $project['id'], 'admin_project_deactivated', [
            'project_slug' => $project['slug'],
            'project_name' => $project['name'],
        ]);

        $_SESSION['tn_flash_notice'] = 'Projeto desativado com sucesso.';

        Response::redirect('/admin/projects');
    }

    private function requireAdminProject(Request $request, string $id): array
    {
        $this->requireAdmin();

        if (!ctype_digit($id)) {
            Response::html($this->layout('Projetos', '<div class="card">Projeto nao encontrado.</div>' . $this->formMarkup()), 404);
        }

        $project = Database::fetchOne(
            'SELECT id, name, slug, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at, created_at
             FROM tn_projects
             WHERE id = :id
             LIMIT 1',
            [
                ':id' => (int) $id,
            ]
        );

        if ($project === null) {
            Response::html($this->layout('Projetos', '<div class="card">Projeto nao encontrado.</div>' . $this->formMarkup()), 404);
        }

        return $project;
    }

    private function flashNotice(string $markup): void
    {
        $_SESSION['tn_flash_notice_markup'] = $markup;
    }

    private function consumeFlashMarkup(): string
    {
        $markup = '';

        if (!empty($_SESSION['tn_flash_notice_markup'])) {
            $markup .= (string) $_SESSION['tn_flash_notice_markup'];
            unset($_SESSION['tn_flash_notice_markup']);
        }

        if (!empty($_SESSION['tn_flash_notice'])) {
            $markup .= '<div class="card success">' . htmlspecialchars((string) $_SESSION['tn_flash_notice'], ENT_QUOTES, 'UTF-8') . '</div>';
            unset($_SESSION['tn_flash_notice']);
        }

        if (!empty($_SESSION['tn_flash_api_key'])) {
            $flash = (array) $_SESSION['tn_flash_api_key'];
            unset($_SESSION['tn_flash_api_key']);
            $markup .= '<section class="card success"><h2>Nova API key gerada</h2>'
                . '<p>Exiba esta chave apenas agora. Ela nao sera mostrada novamente.</p>'
                . '<p><strong>Projeto:</strong> ' . htmlspecialchars((string) ($flash['project_name'] ?? ''), ENT_QUOTES, 'UTF-8') . ' <small>(' . htmlspecialchars((string) ($flash['project_slug'] ?? ''), ENT_QUOTES, 'UTF-8') . ')</small></p>'
                . '<pre class="secret-key">' . htmlspecialchars((string) ($flash['plain_key'] ?? ''), ENT_QUOTES, 'UTF-8') . '</pre>'
                . '</section>';
        }

        return $markup;
    }

    private function layout(string $title, string $body): string
    {
        $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $titleSafe . ' - Tars Notificacoes</title>'
            . '<link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="shell"><header class="topbar"><div><h1>Tars Notificacoes</h1><p>Projetos</p></div>'
            . '<nav><a href="/admin">Dashboard</a><a href="/admin/projects">Projetos</a><a href="/admin/messages">Mensagens</a>'
            . '<form method="post" action="/admin/logout" class="inline"><input type="hidden" name="csrf_token" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '"><button type="submit">Sair</button></form></nav></header>'
            . '<section class="content">' . $body . '</section></main></body></html>';
    }

    private function formMarkup(): string
    {
        return '<section class="card"><h2>Novo projeto</h2><form method="post" action="/admin/projects" class="form">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '">'
            . '<label>Nome</label><input type="text" name="name" required>'
            . '<label>Slug</label><input type="text" name="slug" placeholder="opcional">'
            . '<label>API key</label><input type="password" name="api_key" required placeholder="insira uma chave forte gerada externamente">'
            . '<label>Limite diario</label><input type="number" name="daily_limit" min="0" placeholder="vazio = ilimitado, 0 = bloqueia">'
            . '<label>Limite mensal</label><input type="number" name="monthly_limit" min="0" placeholder="vazio = ilimitado, 0 = bloqueia">'
            . '<label>Limite por minuto</label><input type="number" name="minute_limit" min="0" placeholder="vazio = ilimitado, 0 = bloqueia">'
            . '<label>Max attempts</label><input type="number" name="max_attempts" min="1" value="3">'
            . '<button type="submit">Cadastrar</button></form></section>';
    }

    private function tableMarkup(array $projects): string
    {
        $html = '<section class="card"><h2>Gestao de projetos e API keys</h2><table><thead><tr><th>ID</th><th>Nome</th><th>Slug</th><th>Ativo</th><th>Ultimo uso</th><th>Limite diario</th><th>Limite mensal</th><th>Limite/min</th><th>Max attempts</th><th>Acoes</th></tr></thead><tbody>';
        foreach ($projects as $project) {
            $projectId = (int) $project['id'];
            $csrf = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');
            $html .= '<tr>'
                . '<td>' . $projectId . '</td>'
                . '<td>' . htmlspecialchars((string) $project['name'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $project['slug'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . ((int) $project['active'] === 1 ? 'sim' : 'nao') . '</td>'
                . '<td>' . htmlspecialchars($project['last_used_at'] === null ? 'nunca' : (string) $project['last_used_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($project['daily_limit'] === null ? 'ilimitado' : (string) $project['daily_limit'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($project['monthly_limit'] === null ? 'ilimitado' : (string) $project['monthly_limit'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($project['minute_limit'] === null ? 'ilimitado' : (string) $project['minute_limit'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($project['max_attempts'] ?? 3), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td><div class="table-actions">'
                . '<form method="post" action="/admin/projects/' . $projectId . '/regenerate-key" class="inline">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<button type="submit">Regenerar chave</button></form>'
                . ((int) $project['active'] === 1
                    ? '<form method="post" action="/admin/projects/' . $projectId . '/deactivate" class="inline">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<button type="submit">Desativar</button></form>'
                    : '<form method="post" action="/admin/projects/' . $projectId . '/activate" class="inline">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<button type="submit">Ativar</button></form>')
                . '</div></td>'
                . '</tr>';
        }
        $html .= '</tbody></table></section>';

        return $html;
    }

    private function logAdminAction(int $projectId, string $action, array $details): void
    {
        Database::insert(
            'INSERT INTO tn_sms_logs
                (project_id, sms_message_id, action, status, details_json, created_at)
             VALUES
                (:project_id, NULL, :action, :status, :details_json, NOW())',
            [
                ':project_id' => $projectId,
                ':action' => $action,
                ':status' => 'success',
                ':details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function requireAdmin(): void
    {
        if (!($_SESSION['admin_authenticated'] ?? false) && (string) Env::get('ADMIN_PASSWORD', '') !== '') {
            Response::redirect('/admin');
        }
    }
}
