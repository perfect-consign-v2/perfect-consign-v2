<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;

final class PosLookupController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterGate $gate,
        private readonly ScanSearch $search,
        private readonly GiftIssue $gifts,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/suggest\.json', ['GET'])]
    public function suggest(string $slug): void
    {
        $this->app->open($slug, true);
        $this->send($this->search->matches($this->app->request()->query('q')));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/gift-certificate\.json', ['GET'])]
    public function giftCertificate(string $slug): void
    {
        $this->app->open($slug, true);
        $this->gate->session($slug);
        $number = trim($this->app->request()->query('number'));
        $kind = $this->app->request()->query('kind');
        if (!in_array($kind, ['gift_certificate', 'gift_card', 'store_credit'], true)) {
            $kind = 'gift_certificate';
        }
        $balance = $this->gifts->balance($number, $kind);
        if ($balance === null) {
            $missing = $kind === 'gift_certificate'
                ? RegisterStore::GIFT_CERTIFICATE_MISSING
                : 'that ' . str_replace('_', ' ', $kind) . ' number is not on file';
            $this->send(['ok' => false, 'error' => $missing]);
        }
        $this->send(['ok' => true, 'balance' => View::money($balance), 'cents' => $balance]);
    }

    /**
     * @param array<mixed> $payload
     */
    private function send(array $payload): never
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        try {
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            http_response_code(500);
            echo '[]';
        }
        throw new ResponseSent('json');
    }
}
