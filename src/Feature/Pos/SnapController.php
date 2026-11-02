<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\JsonResponse;

final class SnapController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterGate $gate,
        private readonly SnapSync $snaps,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/snap', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->gate->session($slug);
        $this->send([
            'posted' => false,
            'error' => 'that snap is not on this register',
            'notice' => 'This sale is not posted.',
        ], 422);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/sync', ['POST'])]
    public function sync(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->gate->session($slug);
        $key = $this->app->request()->field('posting_key');
        try {
            $number = $this->snaps->sync($key);
        } catch (DatabaseRefusal $refusal) {
            $this->send(['error' => $this->app->catchRefusal($refusal), 'posted' => false], 422);
        }
        if ($number === null) {
            $this->send([
                'posted' => false,
                'error' => $this->snaps->refusal($key),
                'notice' => 'This sale is not posted.',
            ], 422);
        }
        $this->send(['posted' => true, 'journal_no' => $number]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(array $payload, int $status = 200): never
    {
        JsonResponse::send($payload, $status, 'The ticket could not be sent.');
    }
}
