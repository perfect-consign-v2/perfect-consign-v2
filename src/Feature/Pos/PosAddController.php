<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PosAddController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly RegisterGate $gate,
        private readonly PosPage $page,
        private readonly InstantPiece $instant,
        private readonly SaleHold $sales,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/instant', ['POST'])]
    public function instant(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        $request = $this->app->request();
        try {
            $this->sales->refuse($slug);
            $this->instant->add(
                $session,
                $this->mall->today(),
                $request->field('vendor_id'),
                $request->field('description'),
                $request->field('price'),
                $request->field('barcode'),
                $request->field('quantity'),
                $request->field('brand_id'),
                $request->field('brand_label'),
                $request->field('discount_kind'),
                $request->field('discount_value'),
            );
        } catch (DatabaseRefusal $refusal) {
            $this->page->cart($slug, $this->app->catchRefusal($refusal), true);

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page->cart($slug, $error->getMessage(), true);

            return;
        }
        $stay = $request->field('instant_action') === 'save_and_new';
        $this->app->redirect('/' . $slug . '/pos' . ($stay ? '?instant=1' : ''), 'Piece added.');
    }
}
