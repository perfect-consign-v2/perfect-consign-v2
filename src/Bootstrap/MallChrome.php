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

    public function rentHome(string $slug, bool $open, bool $enabled): ?string
    {
        if (!$open || $enabled || !$this->rentPage($slug)) {
            return null;
        }
        $page = $this->door->actor()->role() === 'cashier' ? 'pos' : 'dashboard';

        return '/' . $slug . '/' . $page;
    }

    private function previewing(string $slug): bool
    {
        return $this->door->actor()->role() === 'owner'
            && VendorPreview::onPortal($this->request->path, $slug)
            && VendorPreview::token($this->request->query('as')) !== '';
    }

    private function rentPage(string $slug): bool
    {
        $root = '/' . $slug . '/';
        if (!str_starts_with($this->request->path, $root)) {
            return false;
        }
        $rest = substr($this->request->path, strlen($root));
        foreach (['rent', 'booths', 'wait-list', 'map'] as $page) {
            if ($rest === $page || str_starts_with($rest, $page . '/')) {
                return true;
            }
        }

        return false;
    }
}
