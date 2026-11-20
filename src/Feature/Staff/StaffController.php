<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Staff;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class StaffController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly StaffStore $staff,
        private readonly MallOwners $owners,
        private readonly Actor $actor,
        private readonly JournalAudit $audit,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff', ['GET'])]
    public function show(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $request = $this->app->request();
        $role = $request->field('role');
        try {
            $flash = $this->addPerson(
                $tenant,
                $role,
                $request->field('username'),
                $request->field('display_name'),
                $request->raw('password'),
                $request->field('email'),
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', $flash);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/([0-9a-fA-F-]{36})/password', ['POST'])]
    public function replace(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $this->kept($id, 'staff.password', function () use ($id, $request): void {
                $this->staff->replaceCashier($id, $request->raw('password'), $request->field('email'));
            });
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', 'Cashier password saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/([0-9a-fA-F-]{36})/manager-password', ['POST'])]
    public function replaceManager(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $this->kept($id, 'staff.password', function () use ($id, $request): void {
                $this->staff->replaceManager($id, $request->raw('password'), $request->field('email'));
            });
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', 'Manager password saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/([0-9a-fA-F-]{36})/books-password', ['POST'])]
    public function replaceBooks(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $this->kept($id, 'staff.password', function () use ($id, $request): void {
                $this->staff->replaceBooks($id, $request->raw('password'), $request->field('email'));
            });
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', 'Books password saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/owners/([0-9a-fA-F-]{36})/password', ['POST'])]
    public function replaceOwner(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $this->kept($id, 'owner.password', function () use ($tenant, $id, $request): void {
                $this->owners->replaceSignIn($tenant, $id, $request->raw('password'), $request->field('email'));
            });
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', 'Owner sign-in saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/owners/([0-9a-fA-F-]{36})/remove', ['POST'])]
    public function removeOwner(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        try {
            $this->kept($id, 'owner.remove', function () use ($tenant, $id): void {
                $this->owners->remove($tenant, $id, $this->actor->account());
            });
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', 'Owner removed.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/([0-9a-fA-F-]{36})/suspend', ['POST'])]
    public function suspendStaff(string $slug, string $id): void
    {
        $this->setStaffAccess($slug, $id, false, 'Person suspended.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/([0-9a-fA-F-]{36})/restore', ['POST'])]
    public function restoreStaff(string $slug, string $id): void
    {
        $this->setStaffAccess($slug, $id, true, 'Person restored.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/([0-9a-fA-F-]{36})/role', ['POST'])]
    public function changeRole(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        try {
            $this->kept($id, 'staff.role', function () use ($id): void {
                $this->staff->changeRole($id, $this->app->request()->field('role'));
            });
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', 'Role saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/owners/([0-9a-fA-F-]{36})/suspend', ['POST'])]
    public function suspendOwner(string $slug, string $id): void
    {
        $this->setOwnerAccess($slug, $id, false, 'Owner suspended.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff/owners/([0-9a-fA-F-]{36})/restore', ['POST'])]
    public function restoreOwner(string $slug, string $id): void
    {
        $this->setOwnerAccess($slug, $id, true, 'Owner restored.');
    }

    private function setStaffAccess(string $slug, string $id, bool $canSignIn, string $flash): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        try {
            $this->kept($id, $canSignIn ? 'staff.restore' : 'staff.suspend', function () use ($id, $canSignIn): void {
                $this->staff->setCanSignIn($id, $canSignIn);
            });
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', $flash);
    }

    private function setOwnerAccess(string $slug, string $id, bool $canSignIn, string $flash): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        try {
            $this->kept($id, $canSignIn ? 'owner.restore' : 'owner.suspend', function () use ($tenant, $id, $canSignIn): void {
                $this->owners->setCanSignIn($tenant, $id, $canSignIn);
            });
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', $flash);
    }

    private function addPerson(
        TenantRecord $tenant,
        string $role,
        string $username,
        string $displayName,
        string $password,
        string $email,
    ): string {
        return match ($role) {
            'cashier' => $this->addCashier($username, $displayName, $password, $email),
            'manager' => $this->addManager($username, $displayName, $password, $email),
            'books' => $this->addBooks($username, $displayName, $password, $email),
            'owner' => $this->addOwner($tenant, $username, $password, $email),
            default => throw new \InvalidArgumentException('Choose a role.'),
        };
    }

    private function addCashier(string $username, string $displayName, string $password, string $email): string
    {
        $this->created('staff.create', function () use ($username, $displayName, $password, $email): string {
            return $this->staff->addCashier($username, $displayName, $password, $email);
        });

        return 'Cashier added.';
    }

    private function addManager(string $username, string $displayName, string $password, string $email): string
    {
        $this->created('staff.create', function () use ($username, $displayName, $password, $email): string {
            return $this->staff->addManager($username, $displayName, $password, $email);
        });

        return 'Manager added.';
    }

    private function addBooks(string $username, string $displayName, string $password, string $email): string
    {
        $this->created('staff.create', function () use ($username, $displayName, $password, $email): string {
            return $this->staff->addBooks($username, $displayName, $password, $email);
        });

        return 'Books login added.';
    }

    private function addOwner(
        TenantRecord $tenant,
        string $username,
        string $password,
        string $email,
    ): string {
        $this->created('owner.create', function () use ($tenant, $username, $password, $email): string {
            return $this->owners->add($tenant, $username, $password, $email);
        });

        return 'Owner added.';
    }

    private function kept(string $id, string $action, callable $work): void
    {
        $this->audit->within(function () use ($id, $action, $work): void {
            $work();
            $this->audit->record($action, $this->subject($action), $id);
        });
    }

    private function created(string $action, callable $work): void
    {
        $this->audit->within(function () use ($action, $work): void {
            $id = $work();
            if (!is_string($id) || $id === '') {
                throw new \RuntimeException('the staff row was not stored');
            }
            $this->audit->record($action, $this->subject($action), $id);
        });
    }

    private function subject(string $action): string
    {
        return str_starts_with($action, 'owner.') ? 'owner' : 'staff';
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Staff/staff', [
            'title' => 'Staff',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'cashiers' => $this->staff->cashiers(),
            'managers' => $this->staff->managers(),
            'readers' => $this->staff->books(),
            'owners' => $this->owners->list($tenant),
            'actorAccount' => $this->actor->account(),
            'error' => $error,
        ]);
    }
}
