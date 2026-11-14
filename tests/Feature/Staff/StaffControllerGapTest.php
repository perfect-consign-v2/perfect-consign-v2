<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Staff;

use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Staff\MallOwners;
use PerfectConsign\Feature\Staff\StaffController;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PDOException;
use PHPUnit\Framework\TestCase;

final class StaffControllerGapTest extends TestCase
{
    use CapturesPage;

    public function testCashierManagerAndBooksRefusalsRender(): void
    {
        $cashier = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/' . MallDoor::PERSON . '/password', [
            'password' => 'password1',
            'email' => 'a@b.co',
        ]));
        $cashier->statements->affectedRefuses = true;
        $html = $this->capture(function () use ($cashier): void {
            $this->controller($cashier)->replace(MallDoor::SLUG, MallDoor::PERSON);
        });
        self::assertStringContainsString('refused', $html);

        $manager = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/' . MallDoor::PERSON . '/manager-password', [
            'password' => 'password1',
            'email' => 'a@b.co',
        ]));
        $manager->statements->affectedRefuses = true;
        $html = $this->capture(function () use ($manager): void {
            $this->controller($manager)->replaceManager(MallDoor::SLUG, MallDoor::PERSON);
        });
        self::assertStringContainsString('refused', $html);

        $books = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/' . MallDoor::PERSON . '/books-password', [
            'password' => 'password1',
            'email' => 'a@b.co',
        ]));
        $books->statements->affectedRefuses = true;
        $html = $this->capture(function () use ($books): void {
            $this->controller($books)->replaceBooks(MallDoor::SLUG, MallDoor::PERSON);
        });
        self::assertStringContainsString('refused', $html);
    }

    public function testOwnerSignInRefusesAndRejectsAMissingOwner(): void
    {
        $refused = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/owners/' . MallDoor::PERSON . '/password', [
            'password' => 'password1',
            'email' => 'a@b.co',
        ]));
        $refused->knobs->executeThrowSql = 'password_hash';
        $refused->knobs->executeError = new PDOException('duplicate');
        $html = $this->capture(function () use ($refused): void {
            $this->controller($refused)->replaceOwner(MallDoor::SLUG, MallDoor::PERSON);
        });
        self::assertStringContainsString('That could not be saved.', $html);

        $missing = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/owners/' . MallDoor::PERSON . '/password', [
            'password' => 'password1',
            'email' => 'a@b.co',
        ]));
        $missing->knobs->updateCount = 0;
        $html = $this->capture(function () use ($missing): void {
            $this->controller($missing)->replaceOwner(MallDoor::SLUG, MallDoor::PERSON);
        });
        self::assertStringContainsString('Choose an owner.', $html);
    }

    public function testRemovingAnOwnerShowsADatabaseRefusal(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/owners/' . MallDoor::VENDOR . '/remove'));
        $door->knobs->executeThrowSql = 'remove_mall_owner';
        $door->knobs->executeError = new PDOException('blocked');
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->removeOwner(MallDoor::SLUG, MallDoor::VENDOR);
        });

        self::assertStringContainsString('That could not be saved.', $html);
    }

    public function testRoleChangesShowBothRefusals(): void
    {
        $books = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/' . MallDoor::PERSON . '/role', [
            'role' => 'cashier',
        ]));
        $books->statements->affectedRefuses = true;
        $html = $this->capture(function () use ($books): void {
            $this->controller($books)->changeRole(MallDoor::SLUG, MallDoor::PERSON);
        });
        self::assertStringContainsString('refused', $html);

        $bad = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/' . MallDoor::PERSON . '/role', [
            'role' => 'owner',
        ]));
        $html = $this->capture(function () use ($bad): void {
            $this->controller($bad)->changeRole(MallDoor::SLUG, MallDoor::PERSON);
        });
        self::assertStringContainsString('Choose cashier, manager, or books.', $html);
    }

    public function testStaffAccessShowsBothRefusals(): void
    {
        $refused = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/' . MallDoor::PERSON . '/suspend'));
        $refused->statements->affectedRefuses = true;
        $html = $this->capture(function () use ($refused): void {
            $this->controller($refused)->suspendStaff(MallDoor::SLUG, MallDoor::PERSON);
        });
        self::assertStringContainsString('refused', $html);

        $missing = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/not-a-person/suspend'));
        $html = $this->capture(function () use ($missing): void {
            $this->controller($missing)->suspendStaff(MallDoor::SLUG, 'not-a-person');
        });
        self::assertStringContainsString('Choose a person.', $html);
    }

    public function testRestoringAnOwnerShowsADatabaseRefusal(): void
    {
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/staff/owners/' . MallDoor::PERSON . '/restore'));
        $door->knobs->executeThrowSql = 'can_sign_in = true';
        $door->knobs->executeError = new DatabaseRefusal('refused');
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->restoreOwner(MallDoor::SLUG, MallDoor::PERSON);
        });

        self::assertStringContainsString('refused', $html);
    }

    /**
     * @param array<string, string> $post
     */
    private function post(string $path, array $post = []): Request
    {
        return new Request('POST', $path, [], ['csrf' => 'csrf-token'] + $post);
    }

    private function controller(MallDoor $door): StaffController
    {
        return new StaffController(
            $door->app,
            new StaffStore($door->statements, new RecoveryAddress(), new PasswordChoice()),
            new MallOwners($door->pdo, $door->roles, new RecoveryAddress(), new PasswordChoice()),
            $door->actor,
        );
    }
}
