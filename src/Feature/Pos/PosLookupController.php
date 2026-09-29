<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Shared\Http\ResponseSent;

final class PosLookupController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly ScanSearch $search,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/suggest\.json', ['GET'])]
    public function suggest(string $slug): void
    {
        $this->app->open($slug, true);
        $this->send($this->search->matches($this->app->request()->query('q')));
    }

    /**
     * @param list<array{code: string, label: string}> $payload
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
