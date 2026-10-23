<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\Request;

/**
 * Register bar, and the pages that close when the mall does not charge booth rent.
 */
final class MallChrome
{
    public function __construct(
        private readonly ActiveRegister $drawers,
        private readonly Session $session,
        private readonly FrontDoor $door,
        private readonly Request $request,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function away(array &$data, string $slug, bool $roled): ?string
    {
        $chosen = $this->drawers->chosen($slug, $roled);
        $data['activeRegister'] = $chosen['name'];
        $data['activeSessionId'] = $chosen['id'];
        $data['chooseRegister'] = $chosen['choose'];
        $leftOpen = $this->drawers->leftOpen($roled);
        $data['leftOpen'] = $leftOpen;
        $vendor = $this->door->actor()->role() === 'vendor';
        if ($vendor) {
            $this->session->delete('close_prior:' . $slug);
        }
        $portal = $vendor || $this->previewing($slug);
        $away = $portal ? null : $this->door->priorShift($slug, $leftOpen);
        $started = $this->session->get('close_prior:' . $slug) === 'yes';
        $data['priorCloseStarted'] = $started;
        $onHub = preg_match('#/registers$#', $this->request->path) === 1;
        $data['priorPrompt'] = !$portal && $leftOpen !== [] && $started !== true && $onHub !== true;

        return $away;
    }

    public function rentHome(string $slug, bool $open, bool $enabled, string $shop = ''): ?string
    {
        if (!$open || !$this->rentPage($slug, $enabled, $shop)) {
            return null;
        }
        $role = $this->door->actor()->role();
        $page = match ($role) {
            'cashier' => 'pos',
            'books' => 'sales',
            default => 'dashboard',
        };

        return '/' . $slug . '/' . $page;
    }

    public function previewAs(string $slug): string
    {
        if ($slug === '' || $this->door->actor()->role() !== 'owner') {
            return '';
        }
        if (!VendorPreview::onPortal($this->request->path, $slug)) {
            return '';
        }

        return VendorPreview::token($this->request->query('as'));
    }

    public function keepFlash(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->session->set($key, $value);
    }

    public function takeFlash(string $key): ?string
    {
        $value = $this->session->get($key);
        if ($this->session->has($key)) {
            $this->session->delete($key);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function previewing(string $slug): bool
    {
        return $this->door->actor()->role() === 'owner'
            && VendorPreview::onPortal($this->request->path, $slug)
            && VendorPreview::token($this->request->query('as')) !== '';
    }

    public function closedRentNote(string $shop): string
    {
        if ($shop === 'consignment') {
            return 'This store does not use booths.';
        }

        return 'This mall does not charge booth rent.';
    }

    private function rentPage(string $slug, bool $rentalsEnabled, string $shop): bool
    {
        $root = '/' . $slug . '/';
        if (!str_starts_with($this->request->path, $root)) {
            return false;
        }
        $rest = substr($this->request->path, strlen($root));
        $pages = [];
        if (!$rentalsEnabled || $shop === 'consignment') {
            $pages = ['rent', 'booths', 'wait-list', 'map'];
        }
        if ($shop === 'consignment') {
            $pages[] = 'mall-sale';
            $pages[] = 'booth-extras';
        }
        foreach ($pages as $page) {
            if ($rest === $page || str_starts_with($rest, $page . '/')) {
                return true;
            }
        }

        return false;
    }
}
