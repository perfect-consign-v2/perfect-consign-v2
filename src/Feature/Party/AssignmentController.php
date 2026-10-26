<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

final class AssignmentController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $parties,
        private readonly AssignmentBook $stays,
        private readonly AssignmentOffer $offers,
        private readonly VendorPage $pages,
        private readonly VendorMove $moves,
        private readonly MallSettings $mall,
        private readonly VendorRoster $roster,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments', ['POST'])]
    public function addAssignment(string $slug): void
    {
        $this->app->checkCsrf();
        $vendorId = $this->app->request()->field('vendor_id');
        $this->attempt($slug, $vendorId, 'Booth assigned.', function (): void {
            $boothId = $this->app->request()->field('booth_id');
            $startsOn = $this->app->request()->field('starts_on');
            $this->stays->addAssignment(
                $boothId,
                $this->app->request()->field('vendor_id'),
                $startsOn,
                $this->app->request()->field('ends_on'),
                $this->offers->contract($boothId, $this->app->request()->field('rent'))
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments/([0-9a-fA-F-]{36})/end', ['POST'])]
    public function endAssignment(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->vendorOf($id);
        $this->attempt($slug, $vendorId, 'Booth assignment ended.', function () use ($id): void {
            $this->stays->endAssignment($id, $this->app->request()->field('ends_on'));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments/([0-9a-fA-F-]{36})/rent', ['POST'])]
    public function changeRent(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->vendorOf($id);
        $this->attempt($slug, $vendorId, 'Rent rate saved.', function () use ($id): void {
            $this->stays->changeRent(
                $id,
                $this->app->request()->field('starts_on'),
                Money::parseMinor($this->app->request()->field('rent')),
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors/([0-9a-fA-F-]{36})/move', ['POST'])]
    public function moveVendor(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $boothId = $this->app->request()->field('booth_id');
        $this->attempt($slug, $id, 'Vendor moved.', function () use ($id, $boothId): void {
            $this->moves->move(
                $id,
                $this->app->request()->field('assignment_id'),
                $boothId,
                $this->app->request()->field('ends_on'),
                $this->offers->contract($boothId, $this->app->request()->field('rent')),
            );
        });
    }

    private function attempt(string $slug, string $vendorId, string $notice, callable $action): void
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
            $this->app->redirect($this->vendorPath($slug, $vendorId), $notice);
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) === 1 && $this->parties->findVendor($vendorId) !== null) {
            $this->pages->show($slug, $vendorId, $error);

            return;
        }
        $tenant = $this->app->open($slug, true);
        $today = $this->mall->today();
        $assembled = $this->roster->assemble($this->stays->vendorRows($today), $today);
        $query = $this->app->request()->query('q');
        $status = $this->roster->selected($this->app->request()->query('status'));
        $this->app->show('Feature/Party/parties', [
            'title' => $this->mall->getPartyLabel('Vendors', 'Consignors'),
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'stats' => $assembled['stats'],
            'vendors' => $this->roster->matching($assembled['vendors'], $status, $query),
            'status' => $status,
            'query' => $query,
            'error' => $error,
        ]);
    }

    private function vendorOf(string $assignmentId): string
    {
        foreach ($this->stays->assignments() as $assignment) {
            if (($assignment['id'] ?? null) !== $assignmentId) {
                continue;
            }
            $vendorId = $assignment['vendor_id'] ?? null;
            if (is_string($vendorId)) {
                return $vendorId;
            }
        }

        return '';
    }

    private function vendorPath(string $slug, string $vendorId): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            return '/' . $slug . '/parties';
        }

        return '/' . $slug . '/vendors/' . $vendorId;
    }
}
