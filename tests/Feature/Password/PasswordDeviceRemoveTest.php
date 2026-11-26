<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Password;

use PerfectConsign\Bootstrap\SignInDevice;
use PerfectConsign\Feature\Password\LoginAccounts;
use PerfectConsign\Feature\Password\OwnPassword;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\PasswordController;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Password\SignInDevices;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

/**
 * Removing a browser from the password page. A browser that is not on the
 * account's list shows the page again with a plain message, not an error.
 */
final class PasswordDeviceRemoveTest extends TestCase
{
    use CapturesPage;

    public function testABrowserNotOnTheListShowsThePageWithAPlainMessage(): void
    {
        foreach (['not-a-browser', '01900000-0000-7000-8000-0000000000d9'] as $deviceId) {
            $door = MallDoor::open($this, new Request(
                'POST',
                '/' . MallDoor::SLUG . '/password/devices/remove',
                [],
                ['csrf' => 'csrf-token', 'device_id' => $deviceId],
            ));
            $page = $this->capture(fn () => $this->controller($door)->removeDevice(MallDoor::SLUG));
            self::assertStringContainsString(SignInDevices::GONE, $page);
            self::assertStringContainsString('Browsers you signed in from', $page);
        }
    }

    private function controller(MallDoor $door): PasswordController
    {
        return new PasswordController(
            $door->app,
            new OwnPassword($door->pdo, $door->roles, new PasswordChoice(), new RecoveryAddress()),
            new LoginAccounts($door->pdo, $door->roles),
            $door->actor,
            QuietAudit::journal($door->statements, $door->actor),
            new SignInDevices($door->pdo, new SignInDevice([], false), $door->roles),
        );
    }
}
