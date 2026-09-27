<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PartyController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $parties,
        private readonly MallSettings $mall,
        private readonly VendorRoster $roster,
        private readonly PayoutStore $payouts,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/parties', ['GET'])]
    public function show(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->form($slug, null, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors', ['POST'])]
    public function addVendor(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $code = $this->app->request()->field('code');
        $firstName = $this->app->request()->field('first_name');
        $lastName = $this->app->request()->field('last_name');
        $phone = $this->app->request()->field('phone');
        $email = $this->app->request()->field('email');
        $address = $this->app->request()->field('address');
        $error = null;
        $id = '';
        try {
            $id = $this->parties->addVendor($code, $firstName, $lastName, $phone, $email, $address);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->vendorError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect($this->vendorPath($slug, $id), 'Vendor created.');
        }
        $this->form($slug, [
            'code' => $code,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
        ], $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors/([0-9a-fA-F-]{36})', ['GET'])]
    public function record(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $this->showRecord($slug, $id, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $vendor = $this->parties->findVendor($id);
        if ($vendor === null) {
            $this->app->notFound();
        }
        $this->form($slug, $vendor, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors/([0-9a-fA-F-]{36})', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $code = $this->app->request()->field('code');
        $firstName = $this->app->request()->field('first_name');
        $lastName = $this->app->request()->field('last_name');
        $phone = $this->app->request()->field('phone');
        $email = $this->app->request()->field('email');
        $address = $this->app->request()->field('address');
        $error = null;
        try {
            $this->parties->saveVendor($id, $code, $firstName, $lastName, $phone, $email, $address);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->vendorError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect($this->vendorPath($slug, $id), 'Vendor saved.');
        }
        $this->form($slug, [
            'id' => $id,
            'code' => $code,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
        ], $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors/([0-9a-fA-F-]{36})/standing', ['POST'])]
    public function setStanding(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $inactive = $this->app->request()->field('standing') === 'inactive';
        if ($inactive) {
            $open = $this->payouts->openPayout($id);
            if ($open['payable'] < 0 || $open['rent'] > 0) {
                $vendor = $this->parties->findVendor($id);
                if ($vendor === null) {
                    $this->app->notFound();
                }
                $this->form($slug, $vendor, 'A vendor who owes the store cannot be made inactive.');

                return;
            }
        }
        $this->parties->setActive($id, !$inactive);
        $this->app->redirect(
            '/' . $slug . '/vendors/' . $id . '/edit',
            $inactive ? 'Vendor is inactive.' : 'Vendor is active.'
        );
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->parties->removeVendor($id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/parties', 'Vendor deleted.');
        }
        $vendor = $this->parties->findVendor($id);
        if ($vendor === null) {
            $this->render($slug, $error);
            return;
        }
        $this->form($slug, $vendor, $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments', ['POST'])]
    public function addAssignment(string $slug): void
    {
        $this->app->checkCsrf();
        $vendorId = $this->app->request()->field('vendor_id');
        $this->attempt($slug, $vendorId, 'Booth assigned.', function (): void {
            $this->parties->addAssignment(
                $this->app->request()->field('booth_id'),
                $this->app->request()->field('vendor_id'),
                $this->app->request()->field('starts_on'),
                $this->app->request()->field('ends_on')
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
            $this->parties->endAssignment($id, $this->app->request()->field('ends_on'));
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
            $this->showRecord($slug, $vendorId, $error);
            return;
        }
        $this->render($slug, $error);
    }

    private function vendorOf(string $assignmentId): string
    {
        foreach ($this->parties->assignments() as $assignment) {
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

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $assembled = $this->roster->assemble(
            $this->parties->vendorRows(),
            $this->parties->booths(),
            $this->mall->today()
        );
        $query = $this->app->request()->query('q');
        $status = $this->roster->selected($this->app->request()->query('status'));
        $this->app->show('Feature/Party/parties', [
            'title' => 'Vendors',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'stats' => $assembled['stats'],
            'vendors' => $this->roster->matching($assembled['vendors'], $status, $query),
            'openBooths' => $assembled['open'],
            'status' => $status,
            'query' => $query,
            'error' => $error,
        ]);
    }

    private function vendorError(string $message): string
    {
        if (str_contains($message, 'vendors_code_key')) {
            return 'That code is already used.';
        }

        return $message;
    }

    /**
     * @param array<string, mixed>|null $vendor
     */
    private function form(string $slug, ?array $vendor, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $editing = $vendor !== null && isset($vendor['id']);
        $this->app->show('Feature/Party/vendor-form', [
            'title' => $editing ? 'Edit vendor' : 'New vendor',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendor' => $vendor,
            'error' => $error,
        ]);
    }

    private function showRecord(string $slug, string $id, ?string $error): void
    {
        $assembled = $this->roster->assemble(
            $this->parties->vendorRows(),
            $this->parties->booths(),
            $this->mall->today()
        );
        $vendor = null;
        foreach ($assembled['vendors'] as $candidate) {
            if ($candidate['id'] === $id) {
                $vendor = $candidate;
                break;
            }
        }
        if ($vendor === null) {
            $this->app->notFound();
        }
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Party/vendor', [
            'title' => $vendor['name'],
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendor' => $vendor,
            'booths' => $this->parties->booths(),
            'error' => $error,
        ]);
    }
}
