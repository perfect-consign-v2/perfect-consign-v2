<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use Psr\Log\LoggerInterface;

final class ItemPhotoIntake
{
    public const int MAX_BYTES = 2000000;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $uploaded = null,
    ) {
    }

    public function rule(): string
    {
        return 'JPEG, PNG, or WebP. ' . $this->label() . ' or smaller.';
    }

    /**
     * @param array{tmp_name: string, error: int, size: int, name: string}|null $file
     */
    public function hex(?array $file, bool $required): ?string
    {
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                throw new \InvalidArgumentException('Choose a photo.');
            }

            return null;
        }
        $this->refuse($file);
        $bytes = file_get_contents($file['tmp_name']);
        if ($bytes === false) {
            $this->logger->error('item photo could not be read', ['error' => error_get_last()]);

            throw new \InvalidArgumentException('That photo could not be read.');
        }
        if ($this->kind($bytes) === null) {
            throw new \InvalidArgumentException('Use a JPEG, PNG, or WebP photo.');
        }

        return bin2hex($bytes);
    }

    /**
     * @param array{tmp_name: string, error: int, size: int, name: string} $file
     */
    private function refuse(array $file): void
    {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > $this->bytes()) {
            throw new \InvalidArgumentException('Use a photo of ' . $this->label() . ' or smaller.');
        }
        if ($file['error'] === UPLOAD_ERR_PARTIAL) {
            throw new \InvalidArgumentException('That photo did not finish uploading. Try again.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] < 1 || !$this->uploaded($file['tmp_name'])) {
            $this->logger->error('item photo upload was refused', ['error' => $file['error'], 'size' => $file['size']]);

            throw new \InvalidArgumentException('That photo could not be read.');
        }
    }

    public function media(string $bytes): ?string
    {
        return $this->kind($bytes);
    }

    public function label(): string
    {
        $bytes = $this->bytes();
        if ($bytes % 1000000 === 0) {
            return intdiv($bytes, 1000000) . ' MB';
        }
        if ($bytes % 1048576 === 0) {
            return intdiv($bytes, 1048576) . ' MB';
        }
        if ($bytes >= 1048576) {
            $shown = number_format($bytes / 1048576, 1, '.', '');

            return rtrim(rtrim($shown, '0'), '.') . ' MB';
        }

        return (string) max(1, (int) ceil($bytes / 1024)) . ' KB';
    }

    public function bytes(): int
    {
        $limit = self::MAX_BYTES;
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $cap = $this->iniBytes($key);
            if ($cap > 0 && $cap < $limit) {
                $limit = $cap;
            }
        }

        return $limit;
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

    private function iniBytes(string $key): int
    {
        $value = ini_get($key);
        if (!is_string($value)) {
            return 0;
        }
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        $bytes = match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };

        return $bytes > 0 ? $bytes : 0;
    }

    private function uploaded(string $path): bool
    {
        if ($this->uploaded instanceof \Closure) {
            return ($this->uploaded)($path) === true;
        }

        return is_uploaded_file($path);
    }
}
