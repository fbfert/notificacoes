<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Support\Env;

final class MessagesController
{
    public function index(Request $request): never
    {
        $this->requireAdmin();

        $messages = Database::fetchAll(
            'SELECT m.id, p.name AS project_name, m.phone, m.message, m.status, m.error_message, m.created_at
             FROM tn_sms_messages m
             INNER JOIN tn_projects p ON p.id = m.project_id
             ORDER BY m.id DESC
             LIMIT 100'
        );

        Response::html($this->layout('Mensagens', $this->tableMarkup($messages)));
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
            . '<link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="shell"><header class="topbar"><div><h1>Tars Notificacoes</h1><p>Mensagens</p></div>'
            . '<nav><a href="/admin">Dashboard</a><a href="/admin/projects">Projetos</a><a href="/admin/messages">Mensagens</a></nav></header>'
            . '<section class="content">' . $body . '</section></main></body></html>';
    }

    private function tableMarkup(array $messages): string
    {
        $html = '<section class="card"><h2>Mensagens</h2><table><thead><tr><th>ID</th><th>Projeto</th><th>Telefone</th><th>Mensagem</th><th>Status</th><th>Erro</th><th>Criada em</th></tr></thead><tbody>';
        foreach ($messages as $message) {
            $html .= '<tr>'
                . '<td>' . (int) $message['id'] . '</td>'
                . '<td>' . htmlspecialchars((string) $message['project_name'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['phone'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['message'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['status'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($message['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['created_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table></section>';

        return $html;
    }
}
