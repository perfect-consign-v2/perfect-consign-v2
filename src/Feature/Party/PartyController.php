<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class PartyController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PartyStore $parties,
        private readonly AssignmentBook $stays,
        private readonly MallSettings $mall,
        private readonly VendorRoster $roster,
        private readonly VendorBalances $balances,
        private readonly VendorForm $forms,
        private readonly VendorPage $pages,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/parties', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $consignmentOnly = $this->mall->isConsignmentOnly();
        $parties = [];
        foreach ($this->parties->parties() as $party) {
            $parties[] = [
                'id' => $party['id'],
                'name' => $party['name'],
                'kind' => $party['kind'],
                'roles' => PartyRoleType::displayNames($party['roles'], $consignmentOnly),
            ];
        }
        $this->app->show('Feature/Party/party-list', [
            'title' => 'Parties',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'parties' => $parties,
            'sellerList' => $this->sellerList($slug),
            'sellerLabel' => $this->mall->getPartyLabel('Vendors', 'Consignors'),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/parties/([0-9a-fA-F-]{36})', ['GET'])]
    public function party(string $slug, string $partyId): void
    {
        $tenant = $this->app->open($slug, true);
        $party = $this->parties->findParty($partyId);
        if ($party === null) {
            $this->app->notFound();
        }
        if (in_array(PartyRoleType::VENDOR, $party['roles'], true)) {
            $vendorId = $this->parties->vendorIdForParty($partyId);
            if ($vendorId !== null) {
                $this->app->redirect($this->sellerRecord($slug, $vendorId));
            }
        }
        if (in_array(PartyRoleType::CUSTOMER, $party['roles'], true)) {
            $this->app->redirect('/' . $slug . '/customers/' . $partyId);
        }
        if (in_array(PartyRoleType::WAIT_LIST, $party['roles'], true)) {
            $this->app->redirect('/' . $slug . '/wait-list/' . $partyId);
        }
        $consignmentOnly = $this->mall->isConsignmentOnly();
        $sellerWord = $this->mall->getPartyLabel('vendor', 'consignor');
        $this->app->show('Feature/Party/party', [
            'title' => $party['name'] !== '' ? $party['name'] : 'Party',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'party' => $party,
            'rolesLabel' => PartyRoleType::displayNames($party['roles'], $consignmentOnly),
            'emptyRolesHint' => 'This party has no ' . $sellerWord . ', customer, or wait-list record to open.',
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)', ['GET'])]
    public function sellers(string $slug): void
    {
        $this->renderSellers($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->forms->form($slug, null, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)', ['POST'])]
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
        $facts = $this->forms->postedFacts();
        $error = null;
        $id = '';
        try {
            $id = $this->parties->addVendor(new VendorDraft([
                'code' => $code,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'agreement_starts_on' => $facts['agreement_starts_on'],
                'agreement_ends_on' => $facts['agreement_ends_on'],
                'tax_id' => $facts['tax_id'],
                'notes' => $facts['notes'],
                'tax_exempt' => $facts['tax_exempt'],
                'tax_exempt_number' => $facts['tax_exempt_number'],
                'business_name' => $this->app->request()->field('business_name'),
                'discount_by' => $facts['discount_by'],
                'keep_days' => $facts['keep_days'],
                'expiry_action' => $facts['expiry_action'],
            ], $this->forms->storedTake($facts['store_take'])));
        } catch (DatabaseRefusal $refusal) {
            $error = $this->forms->vendorError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->parties->setPayoutMode($id, $this->app->request()->field('payout_mode'));
            $this->app->redirect(
                $this->vendorPath($slug, $id),
                $this->mall->getPartyLabel('Vendor created.', 'Consignor created.')
            );
        }
        $this->forms->form($slug, [
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
            'discount_by' => $facts['discount_by'],
            'keep_days' => $facts['keep_days'],
            'expiry_action' => $facts['expiry_action'],
        ], $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})', ['GET'])]
    public function record(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $this->showRecord($slug, $id, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $vendor = $this->parties->findVendor($id);
        if ($vendor === null) {
            $this->app->notFound();
        }
        $this->forms->form($slug, $vendor, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})', ['POST'])]
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
        $facts = $this->forms->postedFacts();
        $error = null;
        try {
            $this->parties->saveVendor($id, new VendorDraft([
                'code' => $code,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'agreement_starts_on' => $facts['agreement_starts_on'],
                'agreement_ends_on' => $facts['agreement_ends_on'],
                'tax_id' => $facts['tax_id'],
                'notes' => $facts['notes'],
                'tax_exempt' => $facts['tax_exempt'],
                'tax_exempt_number' => $facts['tax_exempt_number'],
                'business_name' => $this->app->request()->field('business_name'),
                'discount_by' => $facts['discount_by'],
                'keep_days' => $facts['keep_days'],
                'expiry_action' => $facts['expiry_action'],
            ], $this->forms->storedTake($facts['store_take'])));
        } catch (DatabaseRefusal $refusal) {
            $error = $this->forms->vendorError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->parties->setPayoutMode($id, $this->app->request()->field('payout_mode'));
            $this->app->redirect(
                $this->vendorPath($slug, $id),
                $this->mall->getPartyLabel('Vendor saved.', 'Consignor saved.')
            );
        }
        $this->forms->form($slug, [
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
            'discount_by' => $facts['discount_by'],
            'keep_days' => $facts['keep_days'],
            'expiry_action' => $facts['expiry_action'],
        ], $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/standing', ['POST'])]
    public function setStanding(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $inactive = $this->app->request()->field('standing') === 'inactive';
        $quoted = $this->balances->quote($id, $this->mall->today());
        $payable = $this->balances->payable($id);
        if ($inactive && (($quoted !== null && $quoted['rent'] > 0) || $payable > 0)) {
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
            $this->sellerRecord($slug, $id) . '/edit',
            $this->mall->getPartyLabel(
                $inactive ? 'Vendor is inactive.' : 'Vendor is active.',
                $inactive ? 'Consignor is inactive.' : 'Consignor is active.',
            )
        );
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->parties->removeVendor($id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->forms->vendorError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect(
                $this->sellerList($slug),
                $this->mall->getPartyLabel('Vendor deleted.', 'Consignor deleted.')
            );
        }
        $vendor = $this->parties->findVendor($id);
        if ($vendor === null) {
            $this->renderSellers($slug, $error);

            return;
        }
        $this->forms->form($slug, $vendor, $error);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(?:vendors|consignors)/([0-9a-fA-F-]{36})/customer-role', ['POST'])]
    public function addCustomerRole(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        try {
            $partyId = $this->parties->addCustomerRole($id);
            $this->app->redirect('/' . $slug . '/customers/' . $partyId, 'Customer role added.');
        } catch (\InvalidArgumentException $error) {
            $this->showRecord($slug, $id, $error->getMessage());
        }
    }

    private function vendorPath(string $slug, string $vendorId): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            return $this->sellerList($slug);
        }

        return $this->sellerRecord($slug, $vendorId);
    }

    private function sellerPrefix(): string
    {
        return $this->mall->isConsignmentOnly() ? 'consignors' : 'vendors';
    }

    private function sellerList(string $slug): string
    {
        return '/' . $slug . '/' . $this->sellerPrefix();
    }

    private function sellerRecord(string $slug, string $vendorId): string
    {
        return $this->sellerList($slug) . '/' . $vendorId;
    }

    private function renderSellers(string $slug, ?string $error): void
    {
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
            'sellerPrefix' => $this->sellerPrefix(),
        ]);
    }

    private function refuseInactive(string $slug, string $id): void
    {
        $vendor = $this->parties->findVendor($id);
        if ($vendor === null) {
            $this->app->notFound();
        }
        $this->forms->form($slug, $vendor, 'A vendor who is still owed, or who still owes rent, cannot be made inactive.');
    }

    private function showRecord(string $slug, string $id, ?string $error): void
    {
        $this->pages->show($slug, $id, $error);
    }
}
