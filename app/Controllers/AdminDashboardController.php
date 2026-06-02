<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\TarsNotificationsClient;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Env;

final class AdminDashboardController
{
    public function index(Request $request): never
    {
        $this->requireAdmin();

        $stats = [
            'projects' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_projects')['total'] ?? 0),
            'queued' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "queued"')['total'] ?? 0),
            'sent' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "sent"')['total'] ?? 0),
            'failed' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "failed"')['total'] ?? 0),
            'blocked' => (int) (Database::fetchOne('SELECT COUNT(*) AS total FROM tn_sms_messages WHERE status = "blocked"')['total'] ?? 0),
        ];

        $recentMessages = Database::fetchAll(
            'SELECT m.id, m.phone, m.message, m.status, m.created_at, m.error_message, p.name AS project_name
             FROM tn_sms_messages m
             INNER JOIN tn_projects p ON p.id = m.project_id
             ORDER BY m.id DESC
             LIMIT 10'
        );

        $adminPassword = (string) Env::get('ADMIN_PASSWORD', '');
        $warning = $adminPassword === '' ? '<div class="alert warning">TODO: configure ADMIN_PASSWORD in .env for admin protection.</div>' : '';

        $html = $this->layout('Dashboard', $warning . $this->dashboardMarkup($stats, $recentMessages));

        Response::html($html);
    }

    public function sendTarsNotificationsTest(Request $request): never
    {
        $this->requireAdmin();

        if (!Csrf::validate((string) $request->input('csrf_token', ''))) {
            Response::html($this->layout('Integracao Tars Notificacoes', '<div class="card">CSRF invalido.</div>' . $this->integrationMarkup()), 419);
        }

        $client = new TarsNotificationsClient();
        $result = $client->sendAdministrativeTest(
            Config::tarsNotificationsTestPhone(),
            'Teste de integração do projeto cliente com o Tars Notificações.',
            'admin-test',
            'test'
        );

        $statusLabel = $result['ok']
            ? 'Solicitação aceita pelo gateway.'
            : (($result['skipped'] ?? false) ? 'Integração desativada.' : 'Falha ao enviar solicitação.');

        $body = '<section class="card"><h2>Teste de integração Tars Notificações</h2>'
            . '<p>' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<ul>'
            . '<li><strong>HTTP:</strong> ' . htmlspecialchars((string) ($result['http_status'] ?? 'n/a'), ENT_QUOTES, 'UTF-8') . '</li>'
            . '<li><strong>Status gateway:</strong> ' . htmlspecialchars((string) ($result['gateway_status'] ?? 'n/a'), ENT_QUOTES, 'UTF-8') . '</li>'
            . '<li><strong>Message ID:</strong> ' . htmlspecialchars((string) ($result['message_id'] ?? 'n/a'), ENT_QUOTES, 'UTF-8') . '</li>'
            . '<li><strong>Erro:</strong> ' . htmlspecialchars((string) ($result['error'] ?? 'nenhum'), ENT_QUOTES, 'UTF-8') . '</li>'
            . '</ul>'
            . '<p><a href="/admin">Voltar ao dashboard</a></p>'
            . '</section>';

        Response::html($this->layout('Integracao Tars Notificacoes', $body));
    }

    public function login(Request $request): never
    {
        if (!Csrf::validate((string) $request->input('csrf_token', ''))) {
            Response::html($this->layout('Login', '<div class="card">CSRF invalido.</div>' . $this->loginMarkup()), 419);
        }

        $password = (string) $request->input('password', '');
        $adminPassword = (string) Env::get('ADMIN_PASSWORD', '');

        if ($adminPassword === '') {
            Response::redirect('/admin');
        }

        if (hash_equals($adminPassword, $password)) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            Response::redirect('/admin');
        }

        Response::html($this->layout('Login', '<div class="card">Senha invalida.</div>' . $this->loginMarkup()), 401);
    }

    public function logout(Request $request): never
    {
        if (!Csrf::validate((string) $request->input('csrf_token', ''))) {
            Response::html($this->layout('Dashboard', '<div class="card">CSRF invalido.</div>'), 419);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        Response::redirect('/admin');
    }

    private function requireAdmin(): void
    {
        if (!($_SESSION['admin_authenticated'] ?? false)) {
            if ((string) Env::get('ADMIN_PASSWORD', '') === '') {
                return;
            }

            Response::html($this->layout('Login', $this->loginMarkup()));
        }
    }

    private function layout(string $title, string $body): string
    {
        $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $titleSafe . ' - Tars Notificacoes</title>'
            . '<link rel="stylesheet" href="/assets/css/app.css">'
            . '<script defer src="/assets/js/app.js"></script>'
            . '</head><body><main class="shell"><header class="topbar"><div><h1>Tars Notificacoes</h1><p>Central de notificacoes SMS</p></div>'
            . '<nav><a href="/admin">Dashboard</a><a href="/admin/projects">Projetos</a><a href="/admin/messages">Mensagens</a>'
            . '<form method="post" action="/admin/logout" class="inline"><input type="hidden" name="csrf_token" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '"><button type="submit">Sair</button></form></nav></header>'
            . '<section class="content">' . $body . '</section></main></body></html>';
    }

    private function loginMarkup(): string
    {
        return '<section class="card"><h2>Acesso ao painel</h2><form method="post" action="/admin/login" class="form">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '">'
            . '<label>Senha do painel</label><input type="password" name="password" required>'
            . '<button type="submit">Entrar</button></form></section>';
    }

    private function dashboardMarkup(array $stats, array $recentMessages): string
    {
        $integrationCard = '<section class="card"><h2>Integracao Tars Notificacoes</h2>'
            . '<p>Envio administrativo de teste em modo mock/log. Nenhum SMS real deve ser enviado.</p>'
            . '<p><strong>Base URL:</strong> ' . htmlspecialchars(Config::tarsNotificationsBaseUrl(), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Destino de teste:</strong> ' . htmlspecialchars(Config::tarsNotificationsTestPhone(), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Status:</strong> ' . (Config::tarsNotificationsEnabled() ? 'habilitada' : 'desativada') . '</p>'
            . '<form method="post" action="/admin/tars-notificacoes/test" class="form">'
            . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit">Enviar teste administrativo</button>'
            . '</form>'
            . '</section>';

        $cards = '<div class="grid">';
        foreach ($stats as $label => $value) {
            $cards .= '<div class="card stat"><span>' . htmlspecialchars(ucfirst($label), ENT_QUOTES, 'UTF-8') . '</span><strong>' . (int) $value . '</strong></div>';
        }
        $cards .= '</div>';

        $table = '<section class="card"><h2>Mensagens recentes</h2><table><thead><tr><th>ID</th><th>Projeto</th><th>Telefone</th><th>Mensagem</th><th>Status</th><th>Erro</th><th>Criada em</th></tr></thead><tbody>';
        foreach ($recentMessages as $message) {
            $table .= '<tr>'
                . '<td>' . (int) $message['id'] . '</td>'
                . '<td>' . htmlspecialchars((string) $message['project_name'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['phone'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['message'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['status'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($message['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['created_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }
        $table .= '</tbody></table></section>';

        return $integrationCard . $cards . $table;
    }

    private function integrationMarkup(): string
    {
        return '<section class="card"><h2>Integracao Tars Notificacoes</h2>'
            . '<p>Use o botao no dashboard para enviar um teste administrativo em modo mock/log.</p>'
            . '<p><strong>Observacao:</strong> TARS_NOTIFICACOES_ENABLED deve estar configurado como <code>true</code> para enviar a requisicao.</p>'
            . '</section>';
    }
}
