<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class ApiKeyMiddleware
{
    public function handle(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            Response::json([
                'success' => false,
                'error_code' => 'api_key_missing',
                'message' => 'API key ausente',
            ], 401);
        }

        $projects = Database::fetchAll('SELECT id, name, slug, api_key_hash, active, daily_limit, monthly_limit, minute_limit, max_attempts, last_used_at FROM tn_projects ORDER BY id DESC');
        foreach ($projects as $project) {
            if (password_verify($token, (string) $project['api_key_hash'])) {
                if ((int) $project['active'] !== 1) {
                    Response::json([
                        'success' => false,
                        'error_code' => 'project_inactive',
                        'message' => 'Projeto inativo',
                    ], 403);
                }

                $this->touchLastUsedAt((int) $project['id']);

                return $project;
            }
        }

        Response::json([
            'success' => false,
            'error_code' => 'api_key_invalid',
            'message' => 'API key invalida',
        ], 401);
    }

    private function touchLastUsedAt(int $projectId): void
    {
        Database::execute(
            'UPDATE tn_projects
             SET last_used_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id',
            [
                ':id' => $projectId,
            ]
        );
    }
}
