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

        $projects = Database::fetchAll('SELECT id, name, slug, active, daily_limit, monthly_limit, max_attempts, created_at FROM tn_projects ORDER BY id DESC');
        $notice = '';
        $html = $this->layout('Projetos', $notice . $this->formMarkup() . $this->tableMarkup($projects));

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
        $maxAttempts = $request->input('max_attempts', 3);

        if ($name === '') {
            Response::html($this->layout('Projetos', '<div class="card">Nome do projeto obrigatorio.</div>' . $this->formMarkup()), 422);
        }

        if ($apiKeyPlain === '') {
            Response::html($this->layout('Projetos', '<div class="card">API key obrigatoria. Ela sera salva apenas como hash.</div>' . $this->formMarkup()), 422);
        }

        $dailyLimitValue = $dailyLimit === '' ? null : max(0, (int) $dailyLimit);
        $monthlyLimitValue = $monthlyLimit === '' ? null : max(0, (int) $monthlyLimit);
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
            'INSERT INTO tn_projects (name, slug, api_key_hash, active, daily_limit, monthly_limit, max_attempts, created_at, updated_at)
             VALUES (:name, :slug, :api_key_hash, 1, :daily_limit, :monthly_limit, :max_attempts, NOW(), NOW())',
            [
                ':name' => $name,
                ':slug' => $slug,
                ':api_key_hash' => $hash,
                ':daily_limit' => $dailyLimitValue,
                ':monthly_limit' => $monthlyLimitValue,
                ':max_attempts' => $maxAttemptsValue,
            ]
        );

        $projects = Database::fetchAll('SELECT id, name, slug, active, daily_limit, monthly_limit, max_attempts, created_at FROM tn_projects ORDER BY id DESC');
        $notice = '<div class="card success">Projeto criado com sucesso. A API key foi armazenada apenas como hash e nao pode ser recuperada depois.</div>';

        Response::html($this->layout('Projetos', $notice . $this->formMarkup() . $this->tableMarkup($projects)));
    }

    private function requireAdmin(): void
    {
        if (!($_SESSION['admin_authenticated'] ?? false) && (string) Env::get('ADMIN_PASSWORD', '') !== '') {
            Response::redirect('/admin');
        }
    }

    private function layout(string $title, string $body): string
    {
        $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $titleSafe . ' - Tars Notificacoes</title>'
            . '<link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="shell"><header class="topbar"><div><h1>Tars Notificacoes</h1><p>Projetos</p></div>'
            . '<nav><a href="/admin">Dashboard</a><a href="/admin/projects">Projetos</a><a href="/admin/messages">Mensagens</a></nav></header>'
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
            . '<label>Max attempts</label><input type="number" name="max_attempts" min="1" value="3">'
            . '<button type="submit">Cadastrar</button></form></section>';
    }

    private function tableMarkup(array $projects): string
    {
        $html = '<section class="card"><h2>Projetos cadastrados</h2><table><thead><tr><th>ID</th><th>Nome</th><th>Slug</th><th>Ativo</th><th>Limite diario</th><th>Limite mensal</th><th>Max attempts</th><th>Criado em</th></tr></thead><tbody>';
        foreach ($projects as $project) {
            $html .= '<tr>'
                . '<td>' . (int) $project['id'] . '</td>'
                . '<td>' . htmlspecialchars((string) $project['name'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $project['slug'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . ((int) $project['active'] === 1 ? 'sim' : 'nao') . '</td>'
                . '<td>' . htmlspecialchars($project['daily_limit'] === null ? 'ilimitado' : (string) $project['daily_limit'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($project['monthly_limit'] === null ? 'ilimitado' : (string) $project['monthly_limit'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($project['max_attempts'] ?? 3), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $project['created_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table></section>';

        return $html;
    }
}
