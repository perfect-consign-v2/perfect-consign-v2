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
        $facts = $this->postedFacts();
        $error = null;
        $id = '';
        try {
            $id = $this->parties->addVendor(
                $code,
                $firstName,
                $lastName,
                $phone,
                $email,
                $address,
                $this->storedTake($facts['store_take']),
                $facts['agreement_starts_on'],
                $facts['agreement_ends_on'],
                $facts['tax_id'],
                $facts['notes'],
                $facts['tax_exempt'],
                $facts['tax_exempt_number'],
            );
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
            'store_take' => $facts['store_take'],
            'agreement_starts_on' => $facts['agreement_starts_on'],
            'agreement_ends_on' => $facts['agreement_ends_on'],
            'tax_id' => $facts['tax_id'],
            'notes' => $facts['notes'],
            'tax_exempt' => $facts['tax_exempt'],
            'tax_exempt_number' => $facts['tax_exempt_number'],
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
        $facts = $this->postedFacts();
        $error = null;
        try {
            $this->parties->saveVendor(
                $id,
                $code,
                $firstName,
                $lastName,
                $phone,
                $email,
                $address,
                $this->storedTake($facts['store_take']),
                $facts['agreement_starts_on'],
                $facts['agreement_ends_on'],
                $facts['tax_id'],
                $facts['notes'],
                $facts['tax_exempt'],
                $facts['tax_exempt_number'],
            );
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
            'store_take' => $facts['store_take'],
            'agreement_starts_on' => $facts['agreement_starts_on'],
            'agreement_ends_on' => $facts['agreement_ends_on'],
            'tax_id' => $facts['tax_id'],
            'notes' => $facts['notes'],
            'tax_exempt' => $facts['tax_exempt'],
            'tax_exempt_number' => $facts['tax_exempt_number'],
        ], $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors/([0-9a-fA-F-]{36})/standing', ['POST'])]
    public function setStanding(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $inactive = $this->app->request()->field('standing') === 'inactive';
        if ($inactive && $this->payouts->openPayout($id)['rent'] > 0) {
            $this->refuseInactive($slug, $id);

            return;
        }
        try {
            $this->parties->setActive($id, !$inactive);
        } catch (DatabaseRefusal) {
            $this->refuseInactive($slug, $id);

            return;
        }
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

    private function storedTake(string $entered): ?int
    {
        if ($entered === '') {
            return null;
        }
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $entered, $parts) !== 1) {
            throw new \InvalidArgumentException('store take is a percent from 0 to 100');
        }
        $fraction = str_pad($parts[2] ?? '', 2, '0');
        $basis = ((int) $parts[1] * 100) + (int) $fraction;
        if ($basis > 10000) {
            throw new \InvalidArgumentException('store take is a percent from 0 to 100');
        }
        if ($basis === $this->mall->storeTake()) {
            return null;
        }

        return $basis;
    }

    private function points(mixed $basis): int
    {
        if (is_int($basis)) {
            return $basis;
        }
        if (is_string($basis) && preg_match('/^\d+$/', $basis) === 1) {
            return (int) $basis;
        }

        return 0;
    }

    private function percent(mixed $basis): string
    {
        $points = 0;
        if (is_int($basis)) {
            $points = $basis;
        } elseif (is_string($basis) && preg_match('/^\d+$/', $basis) === 1) {
            $points = (int) $basis;
        }
        $whole = intdiv($points, 100);
        $fraction = $points % 100;
        if ($fraction === 0) {
            return (string) $whole;
        }
        if ($fraction % 10 === 0) {
            return $whole . '.' . (string) intdiv($fraction, 10);
        }

        return $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    private function plain(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function enabled(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /**
     * @return array{
     *     store_take: string,
     *     agreement_starts_on: string,
     *     agreement_ends_on: string,
     *     tax_id: string,
     *     notes: string,
     *     tax_exempt: string,
     *     tax_exempt_number: string
     * }
     */
    private function postedFacts(): array
    {
        return [
            'store_take' => $this->app->request()->field('store_take'),
            'agreement_starts_on' => $this->app->request()->field('agreement_starts_on'),
            'agreement_ends_on' => $this->app->request()->field('agreement_ends_on'),
            'tax_id' => $this->app->request()->field('tax_id'),
            'notes' => $this->app->request()->field('notes'),
            'tax_exempt' => $this->app->request()->field('tax_exempt'),
            'tax_exempt_number' => $this->app->request()->field('tax_exempt_number'),
        ];
    }

    /**
     * @param array<string, mixed>|null $vendor
     */
    private function form(string $slug, ?array $vendor, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $mallBasis = $this->mall->storeTake();
        $shownTake = $this->percent($mallBasis);
        $overridden = false;
        if (is_array($vendor) && array_key_exists('store_take', $vendor) && is_string($vendor['store_take'])) {
            $shownTake = $vendor['store_take'] !== '' ? $vendor['store_take'] : $shownTake;
        } elseif (is_array($vendor) && array_key_exists('commission_bps', $vendor) && $vendor['commission_bps'] !== null) {
            $shownTake = $this->percent($vendor['commission_bps']);
            $overridden = $this->points($vendor['commission_bps']) !== $mallBasis;
        }
        $editing = $vendor !== null && isset($vendor['id']);
        $this->app->show('Feature/Party/vendor-form', [
            'title' => $editing ? 'Edit vendor' : 'New vendor',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'vendor' => $vendor,
            'storeTake' => $shownTake,
            'defaultStoreTake' => $this->percent($mallBasis),
            'overridden' => $overridden,
            'error' => $error,
        ]);
    }

    private function refuseInactive(string $slug, string $id): void
    {
        $vendor = $this->parties->findVendor($id);
        if ($vendor === null) {
            $this->app->notFound();
        }
        $this->form($slug, $vendor, 'A vendor who owes the store cannot be made inactive.');
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
        $stored = $this->parties->findVendor($id);
        if ($stored === null) {
            $this->app->notFound();
        }
        $mallBasis = $this->mall->storeTake();
        $savedBasis = $stored['commission_bps'] ?? null;
        $vendor['store_take'] = $this->percent($savedBasis === null ? $mallBasis : $savedBasis);
        $vendor['store_take_overridden'] = $savedBasis !== null && $this->points($savedBasis) !== $mallBasis;
        $vendor['agreement_starts_on'] = $this->plain($stored['agreement_starts_on'] ?? null);
        $vendor['agreement_ends_on'] = $this->plain($stored['agreement_ends_on'] ?? null);
        $vendor['tax_id'] = $this->plain($stored['tax_id'] ?? null);
        $vendor['notes'] = $this->plain($stored['notes'] ?? null);
        $vendor['tax_exempt'] = $this->enabled($stored['tax_exempt'] ?? false);
        $vendor['tax_exempt_number'] = $this->plain($stored['tax_exempt_number'] ?? null);
        $vendor['first_name'] = $this->plain($stored['first_name'] ?? null);
        $vendor['last_name'] = $this->plain($stored['last_name'] ?? null);
        $vendor['default_store_take'] = $this->percent($mallBasis);
        $open = $this->payouts->openPayout($id);
        $vendor['payable_minor'] = $open['payable'];
        $vendor['rent_minor'] = $open['rent'];
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
