<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Party\SellerPaths;
use PerfectConsign\Ledger\DatabaseRefusal;

final class CustomerController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly CustomerDirectory $directory,
        private readonly CustomerStore $customers,
        private readonly SellerPaths $sellerPaths,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/customers', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $phone = $this->app->request()->query('phone');
        $this->app->show('Feature/Pos/customers', [
            'title' => 'Customers',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'phone' => $phone,
            'customers' => $this->directory->listed($phone),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/customers/new', ['GET'])]
    public function create(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->form($tenant->displayName, $tenant->slug, [
            'id' => '',
            'name' => '',
            'phone' => '',
            'email' => '',
        ], null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/customers', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $name = $this->app->request()->field('customer_name');
        $phone = $this->app->request()->field('customer_phone');
        $email = $this->app->request()->field('customer_email');
        try {
            $partyId = $this->customers->findOrCreate($name, $phone, $email);
            $this->app->redirect('/' . $slug . '/customers/' . $partyId, 'Customer created.');
        } catch (DatabaseRefusal $refusal) {
            $this->form($tenant->displayName, $slug, [
                'id' => '',
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
            ], $this->app->catchRefusal($refusal));
        }
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/customers/([0-9a-fA-F-]{36})(?:/edit)?', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $tenant = $this->app->open($slug, true);
        $customer = $this->customers->find($id);
        if ($customer === null) {
            $this->app->notFound();
        }
        $this->form($tenant->displayName, $tenant->slug, $customer, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/customers/([0-9a-fA-F-]{36})', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $name = $this->app->request()->field('customer_name');
        $phone = $this->app->request()->field('customer_phone');
        $email = $this->app->request()->field('customer_email');
        try {
            $this->customers->save($id, $name, $phone, $email);
            $this->app->redirect('/' . $slug . '/customers/' . $id, 'Customer saved.');
        } catch (DatabaseRefusal $refusal) {
            $customer = $this->customers->find($id) ?? [
                'id' => $id,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
            ];
            $this->form($this->app->open($slug, true)->displayName, $slug, [
                'id' => $customer['id'],
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
            ], $this->app->catchRefusal($refusal));
        }
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/customers/([0-9a-fA-F-]{36})/(?:vendor|consignor|consignors)-role', ['POST'])]
    public function addVendorRole(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $code = trim($this->app->request()->field('code'));
        if ($code === '') {
            $this->app->redirect('/' . $slug . '/customers/' . $id . '/edit', null, 'Enter a seller code.');
        }
        try {
            $vendorId = $this->customers->addVendorRole($id, $code);
            $this->app->redirect($this->sellerPaths->record($slug, $vendorId), 'Vendor role added.');
        } catch (DatabaseRefusal $refusal) {
            $this->app->redirect('/' . $slug . '/customers/' . $id . '/edit', null, $this->app->catchRefusal($refusal));
        }
    }

    /**
     * @param array{id: string, name: string, phone: string, email: string} $customer
     */
    private function form(string $displayName, string $slug, array $customer, ?string $error): void
    {
        $creating = $customer['id'] === '';
        $this->app->show('Feature/Pos/customer-form', [
            'title' => $creating ? 'New customer' : 'Customer',
            'displayName' => $displayName,
            'slug' => $slug,
            'customer' => $customer,
            'error' => $error,
            'sellerList' => $creating ? '' : $this->sellerPaths->list($slug),
            'sellerRoleAction' => $creating ? '' : $this->sellerRoleAction($slug, $customer['id']),
        ]);
    }

    private function sellerRoleAction(string $slug, string $customerId): string
    {
        $role = $this->sellerPaths->prefix() === 'consignors' ? 'consignors-role' : 'vendor-role';

        return '/' . $slug . '/customers/' . $customerId . '/' . $role;
    }
}
