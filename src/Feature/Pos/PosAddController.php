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
        $draftNo = (int) $request->field('draft_no');
        try {
            $this->sales->refuse($slug);
            $today = $this->mall->today();
            $add = new InstantAdd(
                $request->field('vendor_id'),
                $request->field('description'),
                $request->field('price'),
                '',
                '1',
                new InstantBrand($request->field('brand_id'), $request->field('brand_label')),
                $this->postedAttributes($request),
                $request->field('discount_kind'),
                $request->field('discount_value'),
            );
            if ($draftNo > 0) {
                $this->instant->complete($session, $today, $draftNo, $add);
            } else {
                $result = $this->instant->stage($session, $today, $add, $request->field('vendor_label'));
                if ($result === 'draft') {
                    $this->app->redirect(
                        '/' . $slug . '/pos' . ($request->field('instant_action') === 'save_and_new' ? '?instant=1' : ''),
                        'Incomplete line on the ticket.',
                    );
                }
            }
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

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/draft/([1-9][0-9]*)/remove', ['POST'])]
    public function removeDraft(string $slug, string $draftNo): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $session = $this->gate->session($slug);
        $this->instant->discardDraft($session, (int) $draftNo);
        $this->app->redirect('/' . $slug . '/pos', 'Draft line removed.');
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
