<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\SignIn;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\RoleGate;
use PerfectConsign\Bootstrap\SignInDevice;
use PerfectConsign\Feature\Admin\SignInAttempts;
use PerfectConsign\Feature\Password\SignInDevices;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Support\ScriptPdo;
use PHPUnit\Framework\TestCase;

final class SignInListsTest extends TestCase
{
    private const TENANT = '01900000-0000-7000-8000-0000000000aa';

    private const DEVICE = '01900000-0000-7000-8000-0000000000dd';

    public function testTheAttemptListReadsRowsAndNamesOutcomes(): void
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('FROM platform.sign_in_attempts', [
            [
                'at' => '2026-11-25 10:00:00+00', 'door' => 'store', 'email' => 'ada@example.test', 'store' => 'harbor',
                'username' => 'ada', 'address' => '203.0.113.9', 'agent' => 'Firefox', 'outcome' => 'locked_email', 'trusted' => 't',
            ],
            ['at' => null, 'door' => 'admin', 'outcome' => 'ok', 'trusted' => 'f'],
        ]);
        $rows = (new SignInAttempts($pdo))->listed(' ADA@example.test ', '203.0.113.9', 'not-an-outcome');
        self::assertCount(2, $rows);
        self::assertSame('harbor', $rows[0]['store']);
        self::assertTrue($rows[0]['trusted']);
        self::assertSame('', $rows[1]['at']);
        self::assertFalse($rows[1]['trusted']);
        self::assertSame('Refused: too many for this account', SignInAttempts::words('locked_email'));
        self::assertSame('something', SignInAttempts::words('something'));
    }

    public function testTheDeviceListMarksThisBrowserAndForgetsOnlyAListedOne(): void
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('sign_in_device_list', [
            ['id' => self::DEVICE, 'label' => 'Firefox', 'created_at' => '2026-11-01', 'last_seen_at' => '2026-11-25', 'is_current' => 't'],
            ['id' => self::DEVICE, 'label' => '', 'created_at' => null, 'last_seen_at' => null, 'is_current' => 'f'],
            ['id' => null],
        ]);
        $pdo->pushPrepare('sign_in_device_forget', [['sign_in_device_forget' => 't']]);
        $pdo->pushPrepare('sign_in_device_forget', [['sign_in_device_forget' => 'f']]);
        $devices = new SignInDevices($pdo, new SignInDevice([], false), new DatabaseRole($pdo));
        $tenant = new TenantRecord(self::TENANT, 'harbor', 'tenant_' . str_repeat('a', 32), 'poster_' . str_repeat('a', 32), 'Harbor');
        $rows = $devices->listed($tenant, 'ada');
        self::assertCount(2, $rows);
        self::assertTrue($rows[0]['current']);
        self::assertSame('Unknown browser', $rows[1]['label']);
        self::assertSame('', $rows[1]['created_at']);
        $devices->forget($tenant, 'ada', self::DEVICE);
        foreach ([self::DEVICE, 'not-a-device'] as $device) {
            try {
                $devices->forget($tenant, 'ada', $device);
                self::fail('a device that is not listed was forgotten');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(SignInDevices::GONE, $error->getMessage());
            }
        }
    }

    public function testEveryRoleMayRemoveItsOwnBrowsers(): void
    {
        foreach (['cashier', 'manager', 'vendor', 'books'] as $role) {
            self::assertTrue(RoleGate::allows($role, '/password/devices/remove', 'POST'), $role);
        }
    }
}
