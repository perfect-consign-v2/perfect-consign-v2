<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\ActorRole;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

/**
 * The owner moves money the store cannot pay to unclaimed money, pays the
 * vendor from it when they come back, or records it sent to the state.
 */
final class UnclaimedController
{
    private readonly PartySellerPaths $paths;

    public function __construct(
        private readonly MallApp $app,
        private readonly UnclaimedMoney $unclaimed,
        private readonly ActiveRegister $register,
        private readonly MallSettings $mall,
        private readonly Actor $actor,
    ) {
        $this->paths = new PartySellerPaths($mall);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/unclaimed', ['POST'])]
    public function move(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->attempt($slug, $id, 'Moved to unclaimed money. They can now be made inactive.', function () use ($id): void {
            $this->unclaimed->move($id, $this->app->request()->field('reason'), $this->mall->today());
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/unclaimed/pay', ['POST'])]
    public function pay(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $request = $this->app->request();
        $this->attempt($slug, $id, 'Paid from unclaimed money.', function () use ($slug, $id, $request): void {
            $way = $request->field('pay_with');
            $this->unclaimed->pay(
                $id,
                Money::parseMinor($request->field('amount')),
                $way,
                $request->field('check_number'),
                $way === 'cash' ? $this->register->current($slug, true) : '',
                $this->mall->today(),
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/unclaimed/remit', ['POST'])]
    public function remit(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $request = $this->app->request();
        $this->attempt($slug, $id, 'Recorded as sent to the state.', function () use ($id, $request): void {
            $this->unclaimed->remit(
                $id,
                Money::parseMinor($request->field('amount')),
                $request->field('state_reference'),
                $this->mall->today(),
            );
        });
    }

    private function attempt(string $slug, string $id, string $notice, callable $work): void
    {
        $this->app->open($slug, true);
        if ($this->actor->role() !== ActorRole::OWNER) {
            $this->app->notFound();
        }
        try {
            $work();
        } catch (DatabaseRefusal | \InvalidArgumentException $error) {
            $this->app->redirect($this->paths->record($slug, $id), null, $this->app->catchRefusal($error));
        }
        $this->app->redirect($this->paths->record($slug, $id), $notice);
    }
}
