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
        private readonly MallSettings $mall,
        private readonly BoothRoster $roster,
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
                $this->booths->setAskingRent($id, $amount);
            }
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booths/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->booths->removeBooth($id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/booths', 'Booth deleted.');
        }
        $this->list($slug, $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/rates', ['POST'])]
    public function addRate(string $slug): void
    {
        $this->app->checkCsrf();
        $id = $this->app->request()->field('booth_id');
        $this->app->open($slug, true);
        $error = null;
        try {
            $amount = $this->askingAmount();
            if ($amount === null) {
                throw new \InvalidArgumentException('enter an asking rent');
            }
            $this->booths->setAskingRent($id, $amount);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null && preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1) {
            $this->app->redirect('/' . $slug . '/booths/' . $id . '/edit', 'Asking rent saved.');
        }
        $booth = preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1 ? $this->booths->findBooth($id) : null;
        if ($booth === null) {
            $this->list($slug, $error ?? 'that booth is not in this mall');
            return;
        }
        $this->form($slug, $booth, $error);
    }

    private function change(string $slug, ?string $id, callable $action): void
    {
        $this->app->open($slug, true);
        $error = null;
        try {
            $action();
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
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
        }
        $this->form($slug, $booth, $error);
    }

    /**
     * @param array<string, mixed>|null $booth
     */
    private function form(string $slug, ?array $booth, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $id = is_array($booth) ? ($booth['id'] ?? null) : null;
        $this->app->show('Feature/Party/booth-form', [
            'title' => is_string($id) && $id !== '' ? 'Edit booth' : 'New booth',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booth' => $this->withAskingRent($booth),
            'defaultWidth' => $this->mall->boothSize()['width'],
            'defaultDepth' => $this->mall->boothSize()['depth'],
            'error' => $error,
        ]);
    }

    private function list(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $facts = $this->lists->facts();
        $rows = $this->roster->assemble(
            $this->booths->booths(),
            $facts['stays'],
            $this->booths->rates(),
            $facts['open_rent'],
            $facts['blocked'],
            $this->mall->today(),
            $this->mall->boothSize()['width'],
            $this->mall->boothSize()['depth'],
        );
        $query = $this->app->request()->query('q');
        $matched = $this->roster->matching($rows, $query);
        $standing = $this->standing($this->app->request()->query('standing'));
        $counts = ['available' => 0, 'occupied' => 0, 'ending' => 0, 'incoming' => 0];
        foreach ($matched as $row) {
            $status = $row['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $visible = $matched;
        if ($standing !== '' && ($counts[$standing] ?? 0) > 0) {
            $visible = [];
            foreach ($matched as $row) {
                if ($row['status'] === $standing) {
                    $visible[] = $row;
                }
            }
        } else {
            $standing = '';
        }
        $this->app->show('Feature/Party/booths', [
            'title' => 'Booths',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'booths' => $visible,
            'total' => count($rows),
            'query' => $query,
            'standing' => $standing,
            'counts' => $counts,
            'confirm' => $this->roster->confirming($visible, $this->app->request()->query('confirm')),
            'error' => $error,
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
        $minor = $this->booths->askingRent($id);
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
