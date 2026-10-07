<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Feature\Catalog\NameList;
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
            $this->instant->add($session, $this->mall->today(), new InstantAdd(
                $request->field('vendor_id'),
                $request->field('description'),
                $request->field('price'),
                $request->field('barcode'),
                $request->field('quantity'),
                new InstantBrand($request->field('brand_id'), $request->field('brand_label')),
                $this->postedAttributes($request),
                $request->field('discount_kind'),
                $request->field('discount_value'),
            ));
        } catch (DatabaseRefusal $refusal) {
            $this->page->instant($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page->instant($slug, $error->getMessage());

            return;
        }
        $stay = $request->field('instant_action') === 'save_and_new';
        $this->app->redirect('/' . $slug . '/pos' . ($stay ? '?instant=1' : ''), 'Piece added.');
    }

    /**
     * @return array<string, array{id: string, label: string}>
     */
    private function postedAttributes(\PerfectConsign\Shared\Http\Request $request): array
    {
        $fields = [];
        foreach (NameList::all() as $list) {
            $fields[$list->key] = [
                'id' => $request->field($list->key . '_id'),
                'label' => $request->field($list->key . '_label'),
            ];
        }

        return $fields;
    }
}
