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
        private readonly ItemPhotoIntake $intake,
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
        $kind = $this->intake->media($bytes);
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
        $back = '/' . $slug . '/inventory/' . $id . '/edit';
        try {
            $hex = $this->intake->hex($this->app->request()->upload('photo'), true);
        } catch (\InvalidArgumentException $error) {
            $this->app->redirect($back, '', $error->getMessage());
        }
        if ($hex === null) {
            $this->app->redirect($back, '', 'Choose a photo.');
        }
        if (!$this->keep($id, $hex)) {
            $this->app->redirect($back, '', 'The photo was not saved.');
        }
        $this->app->redirect($back, 'Photo saved.');
    }

    public function rule(): string
    {
        return $this->intake->rule();
    }

    /**
     * @param array{tmp_name: string, error: int, size: int, name: string}|null $file
     */
    public function accepted(?array $file): ?string
    {
        return $this->intake->hex($file, false);
    }

    public function keep(string $id, string $hex): bool
    {
        try {
            $this->store($id, $hex);
        } catch (\Throwable $error) {
            $this->logger->error('item photo was not stored', [
                'item' => $id,
                'message' => $error->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function store(string $id, string $hex): void
    {
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

}
