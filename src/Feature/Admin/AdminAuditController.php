<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Routing\Route;
use PDO;

final class AdminAuditController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly PDO $pdo,
    ) {
    }

    #[Route('/admin/audit', ['GET'])]
    public function index(): void
    {
        $this->app->requireAdmin();
        $statement = $this->pdo->query(
            'SELECT admin_email, action, outcome, slug, detail::text AS detail, at
             FROM platform.admin_audit
             ORDER BY at DESC
             LIMIT 200'
        );
        if ($statement === false) {
            throw new \RuntimeException('the admin audit log could not be read');
        }
        $rows = [];
        while ($row = $statement->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $email = $row['admin_email'] ?? null;
            $action = $row['action'] ?? null;
            $outcome = $row['outcome'] ?? null;
            $slug = $row['slug'] ?? null;
            $detail = $row['detail'] ?? null;
            $at = $row['at'] ?? null;
            if (!is_string($email) || !is_string($action) || !is_string($outcome) || !is_string($at)) {
                continue;
            }
            $rows[] = [
                'email' => $email,
                'action' => $action,
                'outcome' => $outcome,
                'slug' => is_string($slug) ? $slug : '',
                'detail' => is_string($detail) ? $detail : '{}',
                'at' => $at,
            ];
        }
        $this->app->show('Feature/Admin/audit', [
            'title' => 'Audit',
            'events' => $rows,
        ]);
    }
}
