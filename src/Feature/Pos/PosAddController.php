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
        private readonly InstantItem $instant,
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
            $today = $this->mall->today();
            $add = InstantAdd::posted([
                'vendor_id' => $request->field('vendor_id'),
                'description' => $request->field('description'),
                'price' => $request->field('price'),
                'quantity' => $request->field('quantity'),
                'brand_id' => $request->field('brand_id'),
                'brand_label' => $request->field('brand_label'),
                'discount_kind' => $request->field('discount_kind'),
                'discount_value' => $request->field('discount_value'),
                'override_user' => $request->field('override_user'),
                'override_password' => $request->field('override_password'),
            ], $this->postedAttributes($request));
            $this->instant->add($session, $today, $add);
        } catch (DatabaseRefusal $refusal) {
            $this->page->instant($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page->instant($slug, $error->getMessage());

            return;
        }
        $stay = $request->field('instant_action') === 'save_and_new';
        $this->app->redirect('/' . $slug . '/pos' . ($stay ? '?instant=1&added=1' : '?instant_added=1'));
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
