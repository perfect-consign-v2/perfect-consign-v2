<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Http\JsonResponse;
use PerfectConsign\Shared\Http\View;

/** JSON pages for the parties table. */
final class PartyRowsController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyPageRead $pages,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/parties/rows', ['GET'])]
    public function rows(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        try {
            $asked = PartyPageQuery::from($this->app->request());
        } catch (\InvalidArgumentException $error) {
            JsonResponse::send(['error' => $error->getMessage()], 422, 'party rows failed');
        }
        $consignmentOnly = $this->mall->isConsignmentOnly();
        $page = $this->pages->page($asked, $consignmentOnly);
        $data = [];
        foreach ($page->rows as $party) {
            $data[] = [
                'DT_RowId' => 'party-' . $party['id'],
                0 => '<a href="/' . View::text($tenant->slug) . '/parties/' . View::text($party['id']) . '">'
                    . View::text($party['name']) . '</a>',
                1 => View::text($party['kind']),
                2 => View::text(PartyRoleType::displayNames($party['roles'], $consignmentOnly)),
            ];
        }
        JsonResponse::send([
            'draw' => $asked->window->draw,
            'recordsTotal' => $page->total,
            'recordsFiltered' => $page->filtered,
            'data' => $data,
        ], 200, 'party rows failed');
    }
}
