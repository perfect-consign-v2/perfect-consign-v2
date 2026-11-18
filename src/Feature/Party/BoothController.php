<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

final class BoothController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $booths,
        private readonly AssignmentBook $asking,
        private readonly MallSettings $mall,
        private readonly BoothRoster $roster,
        private readonly BoothListFilter $filter,
        private readonly BoothListStore $lists,
        private readonly BoothFields $fields,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths', ['GET'])]
    public function show(string $slug): void
    {
        $this->list($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->form($slug, null, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, null, function (): void {
            $size = $this->fields->size();
            $this->booths->addBooth(
                $this->app->request()->field('code'),
                $this->app->request()->field('name'),
                $size['width'],
                $size['depth'],
                $this->askingAmount()
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $booth = $this->booths->findBooth($id);
        if ($booth === null) {
            $this->app->notFound();
        }
        $this->form($slug, $booth, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/([0-9a-fA-F-]{36})', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->change($slug, $id, function () use ($id): void {
            $size = $this->fields->size();
            $this->booths->saveBooth(
                $id,
                $this->app->request()->field('code'),
                $this->app->request()->field('name'),
                $size['width'],
                $size['depth']
            );
            $amount = $this->askingAmount();
            if ($amount !== null) {
                $this->asking->setAskingRent($id, $amount);
            }
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/([0-9a-fA-F-]{36})/active', ['POST'])]
    public function setActive(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $flag = $this->app->request()->field('active');
        if ($flag !== '0' && $flag !== '1') {
            $this->list($slug, 'Choose active or inactive.');

            return;
        }
        $error = $this->caught(function () use ($id, $flag): void {
            $this->lists->setActive($id, $flag === '1', $this->mall->today());
        });
        if ($error !== null) {
            $this->afterActive($slug, $id, $error);

            return;
        }
        $landed = [
            '1' => ['', 'Booth is active.'],
            '0' => ['?visibility=inactive', 'Booth is inactive.'],
        ][$flag];
        $this->app->redirect('/' . $slug . '/booths' . $landed[0], $landed[1]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = $this->caught(function () use ($id): void {
            $this->booths->removeBooth($id);
        });
        if ($error !== null) {
            $this->list($slug, $error);

            return;
        }
        $next = $this->app->request()->query('visibility') === 'inactive' ? '?visibility=inactive' : '';
        $this->app->redirect('/' . $slug . '/booths' . $next, 'Booth deleted.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rates', ['POST'])]
    public function addRate(string $slug): void
    {
        $this->app->checkCsrf();
        $id = $this->app->request()->field('booth_id');
        $this->app->open($slug, true);
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            $this->list($slug, 'that booth is not in this mall');

            return;
        }
        $error = $this->caught(function () use ($id): void {
            $amount = $this->askingAmount();
            if ($amount === null) {
                throw new \InvalidArgumentException('enter an asking rent');
            }
            $this->asking->setAskingRent($id, $amount);
        });
        if ($error !== null) {
            $booth = $this->booths->findBooth($id);
            if ($booth === null) {
                $this->list($slug, $error);

                return;
            }
            $this->form($slug, $booth, $error);

            return;
        }
        $this->app->redirect('/' . $slug . '/booths/' . $id . '/edit', 'Asking rent saved.');
    }

    private function afterActive(string $slug, string $id, string $error): void
    {
        if ($this->app->request()->field('back') !== 'booth') {
            $this->list($slug, $error);

            return;
        }
        $booth = $this->booths->findBooth($id);
        if ($booth === null) {
            $this->list($slug, $error);

            return;
        }
        $this->form($slug, $booth, $error);
    }

    private function caught(callable $action): ?string
    {
        try {
            $action();
        } catch (DatabaseRefusal $refusal) {
            return $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            return $errorValue->getMessage();
        }

        return null;
    }

    private function change(string $slug, ?string $id, callable $action): void
    {
        $this->app->open($slug, true);
        $error = $this->caught($action);
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/booths', $id === null ? 'Booth created.' : 'Booth saved.');
        }
        $booth = [
            'code' => $this->app->request()->field('code'),
            'name' => $this->app->request()->field('name'),
            'width_ft' => $this->app->request()->field('width_ft'),
            'depth_ft' => $this->app->request()->field('depth_ft'),
            'amount' => $this->app->request()->field('amount'),
        ];
        if ($id !== null) {
            $booth['id'] = $id;
            $existing = $this->booths->findBooth($id);
            if ($existing !== null) {
                $booth['active'] = $existing['active'] ?? true;
            }
        }
        $this->form($slug, $booth, $error);
    }

    /**
     * @param array<string, mixed>|null $booth
     */
    private function form(string $slug, ?array $booth, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $standing = $this->lists->standing(is_array($booth) ? $booth : null, $this->mall->today());
        $this->app->show('Feature/Party/booth-form', [
            'title' => $standing['show'] ? 'Edit booth' : 'New booth',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booth' => $this->withAskingRent($booth),
            'defaultWidth' => $this->mall->boothSize()['width'],
            'defaultDepth' => $this->mall->boothSize()['depth'],
            'showStanding' => $standing['show'],
            'boothActive' => $standing['active'],
            'canRetire' => $standing['retire'],
            'error' => $error,
        ]);
    }

    private function list(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $facts = $this->lists->facts();
        $raw = $this->booths->booths();
        $offered = $this->filter->offered($raw, $this->app->request()->query('visibility'));
        $rows = $this->roster->assemble(
            $offered['rows'],
            $facts['stays'],
            $this->lists->rates(),
            $facts['open_rent'],
            $facts['blocked'],
            $this->mall->today(),
            $this->mall->boothSize()['width'],
            $this->mall->boothSize()['depth'],
        );
        $query = $this->app->request()->query('q');
        $matched = $this->filter->matching($rows, $query);
        $standing = $this->standing($this->app->request()->query('standing'));
        $counts = $this->filter->legend($matched);
        $visible = $matched;
        if ($standing !== '' && ($counts[$standing] ?? 0) > 0) {
            $visible = $this->filter->narrow($matched, $standing);
        } else {
            $standing = '';
        }
        $this->app->show('Feature/Party/booths', [
            'title' => 'Booths',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booths' => $visible,
            'total' => count($rows),
            'mallTotal' => count($raw),
            'visibility' => $offered['visibility'],
            'activeCount' => $offered['active'],
            'inactiveCount' => $offered['inactive'],
            'query' => $query,
            'standing' => $standing,
            'counts' => $counts,
            'confirm' => $this->filter->confirming($visible, $this->app->request()->query('confirm')),
            'error' => $error,
            'sellerPrefix' => $this->mall->isConsignmentOnly() ? 'consignors' : 'vendors',
        ]);
    }

    /**
     * @param array<string, mixed>|null $booth
     * @return array<string, mixed>|null
     */
    private function withAskingRent(?array $booth): ?array
    {
        if ($booth === null) {
            return null;
        }
        if (($booth['amount'] ?? '') !== '') {
            return $booth;
        }
        $id = $booth['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return $booth;
        }
        $minor = $this->asking->askingRent($id);
        if ($minor !== null) {
            $booth['amount'] = \PerfectConsign\Shared\Http\View::money($minor);
        }

        return $booth;
    }

    private function askingAmount(): ?int
    {
        $amount = $this->app->request()->field('amount');
        if ($amount === '') {
            return null;
        }

        return Money::parseMinor($amount);
    }

    private function standing(string $value): string
    {
        if (in_array($value, ['available', 'occupied', 'ending', 'incoming'], true)) {
            return $value;
        }

        return '';
    }
}
