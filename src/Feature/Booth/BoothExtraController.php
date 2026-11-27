<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Booth;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\SignedInPerson;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Ledger\Money;

/**
 * An extra charge on a stay (electric, a sign), and booth deposits for stores
 * that take them. A person chooses the stay, types the amount and a reference;
 * the database posts it on that stay and refuses what the store does not allow.
 */
final class BoothExtraController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PostingFunctions $postings,
        private readonly ActiveRegister $register,
        private readonly Actor $actor,
        private readonly JournalAudit $audit,
        private readonly BoothStayChoices $stays,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booth-extras', ['GET'])]
    public function show(string $slug): void
    {
        $this->page($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booth-extras', ['POST'])]
    public function charge(string $slug): void
    {
        $this->post($slug, 'Booth charge', function (string $stay, int $amount, string $reference, string $today): string {
            return $this->postings->post(
                $today,
                'stall_post_booth_charge(?::uuid, ?, ?, ?::date)',
                [$stay, $amount, $reference, $today],
                null,
                null,
                null,
                $this->audited('booth_charge'),
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booth-deposit', ['POST'])]
    public function deposit(string $slug): void
    {
        $this->post($slug, 'Booth deposit', fn (string $stay, int $amount, string $reference, string $today): string
            => $this->depositMove($slug, $stay, $amount, $reference, $today, false));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/booth-deposit/release', ['POST'])]
    public function release(string $slug): void
    {
        $this->post($slug, 'Booth deposit returned', fn (string $stay, int $amount, string $reference, string $today): string
            => $this->depositMove($slug, $stay, $amount, $reference, $today, true));
    }

    /**
     * @param callable(string, int, string, string): string $posting
     */
    private function post(string $slug, string $description, callable $posting): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $stay = $request->field('assignment_id');
            $reference = trim($request->field('source_reference'));
            if ($reference === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $stay) !== 1) {
                throw new DatabaseRefusal('Choose the booth and enter a reference.');
            }
            SignedInPerson::require($this->actor->name(), $this->actor->role());
            $posting($stay, Money::parseMinor($request->field('amount')), $reference, $this->mall->today());
        } catch (DatabaseRefusal $refusal) {
            $this->page($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->page($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/booth-extras', $description . ' posted.');
    }

    private function depositMove(string $slug, string $stay, int $amount, string $reference, string $today, bool $releasing): string
    {
        $session = $this->register->current($slug, true);
        if ($session === '') {
            throw new DatabaseRefusal('Open the register first to take or return cash.');
        }

        return $this->postings->post(
            $today,
            'stall_post_booth_deposit(?::uuid, ?, ?, ?::date, ?::boolean)',
            [$stay, $amount, $reference, $today, $releasing ? 'true' : 'false'],
            $session,
            null,
            null,
            $this->audited('booth_deposit'),
        );
    }

    /**
     * @return callable(string): void
     */
    private function audited(string $action): callable
    {
        return function (string $journalId) use ($action): void {
            $this->audit->record($action, 'journal', $journalId);
        };
    }

    private function page(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Booth/booth-extras', [
            'title' => 'Booth extras',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => $error,
            'stays' => $this->stays->current($this->mall->today()),
            'held' => $this->stays->depositsHeld(),
            'depositsOn' => $this->stays->depositsEnabled(),
        ]);
    }
}
