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

        $filters = [
            'project' => trim((string) $request->input('project', '')),
            'status' => trim((string) $request->input('status', '')),
            'type' => trim((string) $request->input('type', '')),
            'phone' => trim((string) $request->input('phone', '')),
            'from' => trim((string) $request->input('from', '')),
            'to' => trim((string) $request->input('to', '')),
            'message_id' => trim((string) $request->input('message_id', '')),
        ];

        [$where, $params] = $this->buildWhere($filters);

        $messages = Database::fetchAll(
            'SELECT m.id, m.project_id, p.name AS project_name, p.slug AS project_slug, m.phone, m.message, m.type, m.status, m.provider, m.provider_message_id, m.error_message, m.attempts, m.max_attempts, m.sent_at, m.delivered_at, m.failed_at, m.created_at
             FROM tn_sms_messages m
             INNER JOIN tn_projects p ON p.id = m.project_id
             ' . $where . '
             ORDER BY m.id DESC
             LIMIT 100',
            $params
        );

        Response::html($this->layout('Mensagens', $this->filtersMarkup($filters) . $this->tableMarkup($messages)));
    }

    public function show(Request $request, string $id): never
    {
        $this->requireAdmin();

        if (!ctype_digit($id)) {
            Response::html($this->layout('Mensagem', '<div class="card">Mensagem nao encontrada.</div>'), 404);
        }

        $message = Database::fetchOne(
            'SELECT m.*, p.name AS project_name, p.slug AS project_slug
             FROM tn_sms_messages m
             INNER JOIN tn_projects p ON p.id = m.project_id
             WHERE m.id = :id
             LIMIT 1',
            [
                ':id' => (int) $id,
            ]
        );

        if ($message === null) {
            Response::html($this->layout('Mensagem', '<div class="card">Mensagem nao encontrada.</div>'), 404);
        }

        $logs = Database::fetchAll(
            'SELECT action, status, details_json, created_at
             FROM tn_sms_logs
             WHERE sms_message_id = :sms_message_id
             ORDER BY id ASC',
            [
                ':sms_message_id' => (int) $id,
            ]
        );

        $body = $this->detailMarkup($message, $logs);
        Response::html($this->layout('Mensagem', $body));
    }

    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if ($filters['project'] !== '') {
            if (ctype_digit($filters['project'])) {
                $where[] = 'm.project_id = :project_id';
                $params[':project_id'] = (int) $filters['project'];
            } else {
                $where[] = 'p.slug = :project_slug';
                $params[':project_slug'] = $filters['project'];
            }
        }

        if ($filters['status'] !== '') {
            $where[] = 'm.status = :status';
            $params[':status'] = $filters['status'];
        }

        if ($filters['type'] !== '') {
            $where[] = 'm.type = :type';
            $params[':type'] = $filters['type'];
        }

        if ($filters['phone'] !== '') {
            $digits = preg_replace('/\D+/', '', $filters['phone']);
            if ($digits !== '') {
                $where[] = 'm.phone LIKE :phone';
                $params[':phone'] = '%' . $digits . '%';
            }
        }

        if ($filters['message_id'] !== '' && ctype_digit($filters['message_id'])) {
            $where[] = 'm.id = :message_id';
            $params[':message_id'] = (int) $filters['message_id'];
        }

        if ($filters['from'] !== '') {
            $where[] = 'm.created_at >= :from_date';
            $params[':from_date'] = $filters['from'] . ' 00:00:00';
        }

        if ($filters['to'] !== '') {
            $where[] = 'm.created_at <= :to_date';
            $params[':to_date'] = $filters['to'] . ' 23:59:59';
        }

        $sql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        return [$sql, $params];
    }

    private function filtersMarkup(array $filters): string
    {
        $html = '<section class="card"><h2>Filtros</h2><form method="get" action="/admin/messages" class="form filters">';
        $html .= '<label>Projeto</label><input type="text" name="project" value="' . htmlspecialchars($filters['project'], ENT_QUOTES, 'UTF-8') . '" placeholder="ID ou slug">';
        $html .= '<label>Status</label><input type="text" name="status" value="' . htmlspecialchars($filters['status'], ENT_QUOTES, 'UTF-8') . '" placeholder="queued, sent, failed, blocked">';
        $html .= '<label>Type</label><input type="text" name="type" value="' . htmlspecialchars($filters['type'], ENT_QUOTES, 'UTF-8') . '" placeholder="transactional, alert, test">';
        $html .= '<label>Telefone</label><input type="text" name="phone" value="' . htmlspecialchars($filters['phone'], ENT_QUOTES, 'UTF-8') . '" placeholder="DDD e numero">';
        $html .= '<label>De</label><input type="date" name="from" value="' . htmlspecialchars($filters['from'], ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<label>Ate</label><input type="date" name="to" value="' . htmlspecialchars($filters['to'], ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<label>Message ID</label><input type="text" name="message_id" value="' . htmlspecialchars($filters['message_id'], ENT_QUOTES, 'UTF-8') . '" placeholder="id numerico">';
        $html .= '<button type="submit">Aplicar filtros</button>';
        $html .= '<a class="button secondary" href="/admin/messages">Limpar</a>';
        $html .= '</form></section>';

        return $html;
    }

    private function tableMarkup(array $messages): string
    {
        $html = '<section class="card"><h2>Mensagens</h2><table><thead><tr><th>ID</th><th>Projeto</th><th>Telefone</th><th>Type</th><th>Status</th><th>Erro</th><th>Criada em</th><th>Detalhe</th></tr></thead><tbody>';
        foreach ($messages as $message) {
            $html .= '<tr>'
                . '<td>' . (int) $message['id'] . '</td>'
                . '<td>' . htmlspecialchars((string) $message['project_name'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['phone'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['type'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['status'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($message['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $message['created_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td><a href="/admin/messages/' . (int) $message['id'] . '">Abrir</a></td>'
                . '</tr>';
        }
        $html .= '</tbody></table></section>';

        return $html;
    }

    private function detailMarkup(array $message, array $logs): string
    {
        $json = static function (mixed $value): string {
            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
            }

            return (string) $value;
        };

        $summary = '<section class="card"><h2>Mensagem #' . (int) $message['id'] . '</h2><div class="grid">';
        $summary .= $this->statBox('Projeto', (string) $message['project_name']);
        $summary .= $this->statBox('Slug', (string) $message['project_slug']);
        $summary .= $this->statBox('Telefone', (string) $message['phone']);
        $summary .= $this->statBox('Type', (string) $message['type']);
        $summary .= $this->statBox('Status', (string) $message['status']);
        $summary .= $this->statBox('Provider', (string) ($message['provider'] ?? 'mock'));
        $summary .= $this->statBox('Provider message ID', (string) ($message['provider_message_id'] ?? 'n/a'));
        $summary .= $this->statBox('Attempts', (string) ($message['attempts'] ?? 0));
        $summary .= $this->statBox('Max attempts', (string) ($message['max_attempts'] ?? 0));
        $summary .= $this->statBox('Criada em', (string) $message['created_at']);
        $summary .= $this->statBox('Enviada em', (string) ($message['sent_at'] ?? 'n/a'));
        $summary .= $this->statBox('Entregue em', (string) ($message['delivered_at'] ?? 'n/a'));
        $summary .= $this->statBox('Falha em', (string) ($message['failed_at'] ?? 'n/a'));
        $summary .= '</div>';
        $summary .= '<div class="card"><h3>Mensagem</h3><p>' . nl2br(htmlspecialchars((string) $message['message'], ENT_QUOTES, 'UTF-8')) . '</p></div>';
        $summary .= '<div class="card"><h3>Erro resumido</h3><p>' . htmlspecialchars((string) ($message['error_message'] ?? 'nenhum'), ENT_QUOTES, 'UTF-8') . '</p></div>';
        $summary .= '<p><a href="/admin/messages">Voltar para a lista</a></p></section>';

        $timeline = '<section class="card"><h2>Timeline de logs</h2>';
        if ($logs === []) {
            $timeline .= '<p>Nenhum log encontrado.</p>';
        } else {
            $timeline .= '<table><thead><tr><th>Data</th><th>Acao</th><th>Status</th><th>Detalhes</th></tr></thead><tbody>';
            foreach ($logs as $log) {
                $timeline .= '<tr>'
                    . '<td>' . htmlspecialchars((string) $log['created_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars((string) $log['action'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars((string) $log['status'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td><pre class="log-json">' . htmlspecialchars($json($log['details_json'] ?? ''), ENT_QUOTES, 'UTF-8') . '</pre></td>'
                    . '</tr>';
            }
            $timeline .= '</tbody></table>';
        }
        $timeline .= '</section>';

        return $summary . $timeline;
    }

    private function statBox(string $label, string $value): string
    {
        return '<div class="card stat"><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><strong>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</strong></div>';
    }

    private function layout(string $title, string $body): string
    {
        $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $titleSafe . ' - Tars Notificacoes</title>'
            . '<link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="shell"><header class="topbar"><div><h1>Tars Notificacoes</h1><p>Mensagens</p></div>'
            . '<nav><a href="/admin">Dashboard</a><a href="/admin/projects">Projetos</a><a href="/admin/messages">Mensagens</a>'
            . '<form method="post" action="/admin/logout" class="inline"><input type="hidden" name="csrf_token" value="' . htmlspecialchars(\App\Support\Csrf::token(), ENT_QUOTES, 'UTF-8') . '"><button type="submit">Sair</button></form></nav></header>'
            . '<section class="content">' . $body . '</section></main></body></html>';
    }

    private function requireAdmin(): void
    {
        if (!($_SESSION['admin_authenticated'] ?? false) && (string) Env::get('ADMIN_PASSWORD', '') !== '') {
            Response::redirect('/admin');
        }
    }
}
