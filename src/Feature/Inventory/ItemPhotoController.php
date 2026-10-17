<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallGuard;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\ResponseSent;
use Psr\Log\LoggerInterface;

final class ItemPhotoController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Statements $statements,
        private readonly Actor $actor,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/([0-9a-fA-F-]{36})/photo', ['GET'])]
    public function show(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $row = $this->stored($id);
        if ($row === null || !$this->visible($row['vendor_id'])) {
            $this->app->notFound();
        }
        $bytes = hex2bin($row['photo']);
        if ($bytes === false) {
            $this->app->notFound();
        }
        $kind = $this->kind($bytes);
        if ($kind === null) {
            $this->app->notFound();
        }
        header('Content-Type: ' . $kind);
        header('Content-Length: ' . (string) strlen($bytes));
        header('Cache-Control: private, max-age=3600');
        echo $bytes;
        throw new ResponseSent('photo');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/([0-9a-fA-F-]{36})/photo', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        if ($this->ownerId($id) === null) {
            $this->app->notFound();
        }
        $file = $this->app->request()->upload('photo');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->app->redirect('/' . $slug . '/inventory/' . $id . '/edit', '', 'Choose a photo.');
        }
        if ($file['size'] < 1 || $file['size'] > 2000000) {
            $this->app->redirect('/' . $slug . '/inventory/' . $id . '/edit', '', 'Use a photo under 2 MB.');
        }
        $bytes = file_get_contents($file['tmp_name']);
        if ($bytes === false) {
            $this->logger->error('item photo could not be read', ['item' => $id, 'error' => error_get_last()]);
            $this->app->redirect('/' . $slug . '/inventory/' . $id . '/edit', '', 'That photo could not be read.');
        }
        if ($this->kind($bytes) === null) {
            $this->app->redirect('/' . $slug . '/inventory/' . $id . '/edit', '', 'Use a JPEG, PNG, or WebP photo.');
        }
        $hex = bin2hex($bytes);
        $this->statements->transaction(function () use ($id, $hex): void {
            $this->statements->change(
                'INSERT INTO item_photos (item_id, photo) VALUES (?::uuid, decode(?, \'hex\'))
                 ON CONFLICT (item_id) DO UPDATE SET photo = EXCLUDED.photo',
                [$id, $hex]
            );
            $this->statements->change(
                'UPDATE items SET has_photo = true WHERE id = ?::uuid',
                [$id]
            );
        });
        $this->app->redirect('/' . $slug . '/inventory/' . $id . '/edit', 'Photo saved.');
    }

    public function has(string $id): bool
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            return false;
        }
        $rows = $this->statements->bound(
            'SELECT has_photo FROM items WHERE id = ?::uuid',
            [$id]
        );

        return MallGuard::flag($rows[0]['has_photo'] ?? null);
    }

    /**
     * @return array{vendor_id: string, photo: string}|null
     */
    private function stored(string $id): ?array
    {
        $rows = $this->statements->bound(
            "SELECT item.vendor_id::text AS vendor_id, encode(photo.photo, 'hex') AS photo
             FROM item_photos AS photo
             JOIN items AS item ON item.id = photo.item_id
             WHERE photo.item_id = ?::uuid",
            [$id]
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $vendorId = $row['vendor_id'] ?? null;
        $photo = $row['photo'] ?? null;
        if (!is_string($vendorId) || !is_string($photo) || $photo === '') {
            return null;
        }

        return ['vendor_id' => $vendorId, 'photo' => $photo];
    }

    private function ownerId(string $id): ?string
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id FROM items WHERE id = ?::uuid',
            [$id]
        );
        $found = $rows[0]['id'] ?? null;

        return is_string($found) ? $found : null;
    }

    private function visible(string $vendorId): bool
    {
        if ($this->actor->role() !== 'vendor') {
            return true;
        }

        return $this->actor->vendor() === $vendorId;
    }

    private function kind(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }
}
