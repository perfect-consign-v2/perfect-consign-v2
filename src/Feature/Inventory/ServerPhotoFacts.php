<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class ServerPhotoFacts implements PhotoFacts
{
    public function uploaded(string $path): bool
    {
        return is_uploaded_file($path);
    }

    public function ini(string $key): string
    {
        $value = ini_get($key);

        return is_string($value) ? $value : '';
    }
}
