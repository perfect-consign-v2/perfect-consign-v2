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
        $balance = $this->gifts->balance($number);
        if ($balance === null) {
            $this->send(['ok' => false, 'error' => RegisterStore::GIFT_CERTIFICATE_MISSING]);
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
