<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Ledger\DatabaseRefusal;

final class VendorSignInController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly StaffStore $staff,
        private readonly VendorPage $pages,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendors/([0-9a-fA-F-]{36})/sign-in', ['POST'])]
    public function add(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $this->staff->addVendor(
                $request->field('username'),
                $request->field('display_name'),
                $request->raw('password'),
                $id,
            );
        } catch (DatabaseRefusal $refusal) {
            $this->pages->show($slug, $id, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->pages->show($slug, $id, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/vendors/' . $id, 'Vendor sign-in added.');
    }
}
