<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Company;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class CompanyController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly CompanyStore $company,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/company', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->form($slug, $this->company->profile($tenant), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/company', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $posted = $this->posted();
        try {
            $this->company->save($tenant, CompanyDraft::parse($posted));
        } catch (DatabaseRefusal $refusal) {
            $this->form($slug, $posted, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->form($slug, $posted, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/company', 'Company saved.');
    }

    /**
     * @param array{name: string, street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string} $company
     */
    private function form(string $slug, array $company, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Company/company', [
            'title' => 'Company',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'company' => $company,
            'error' => $error,
        ]);
    }

    /**
     * @return array{name: string, street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string}
     */
    private function posted(): array
    {
        $request = $this->app->request();

        return [
            'name' => $request->field('store_name'),
            'street' => $request->field('street'),
            'street2' => $request->field('street2'),
            'city' => $request->field('city'),
            'state' => $request->field('state'),
            'postal' => $request->field('postal'),
            'phone' => $request->field('phone'),
            'email' => $request->field('email'),
        ];
    }
}
