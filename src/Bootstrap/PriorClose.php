<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Shared\Http\Request;

final class PriorClose
{
    public function __construct(
        private readonly Session $session,
        private readonly Request $request,
    ) {
    }

    /**
     * @param list<array{id: string, name: string, opened: string}> $leftOpen
     */
    public function redirect(string $slug, array $leftOpen): ?string
    {
        $key = 'close_prior:' . $slug;
        if ($leftOpen === []) {
            $this->session->delete($key);

            return null;
        }
        $id = $leftOpen[0]['id'];
        if (preg_match('#/pos(/|$)#', $this->request->path) === 1) {
            return null;
        }
        if ($this->closing($leftOpen) !== null) {
            $this->session->set($key, 'yes');

            return null;
        }
        if ($this->session->get($key) === 'yes') {
            return '/' . $slug . '/registers?close=' . rawurlencode($id);
        }

        return null;
    }

    /**
     * @param list<array{id: string, name: string, opened: string}> $leftOpen
     */
    private function closing(array $leftOpen): ?string
    {
        $path = $this->request->path;
        if (preg_match('#/registers/([0-9a-fA-F-]{36})/close$#', $path, $match) === 1) {
            return $match[1];
        }
        if (preg_match('#/registers$#', $path) !== 1) {
            return null;
        }
        $query = $this->request->query('close');
        foreach ($leftOpen as $row) {
            if ($row['id'] === $query) {
                return $query;
            }
        }

        return null;
    }
}
