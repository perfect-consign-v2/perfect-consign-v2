<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Bootstrap;

use PerfectConsign\Bootstrap\SignedInPerson;
use PerfectConsign\Ledger\DatabaseRefusal;
use PHPUnit\Framework\TestCase;

final class SignedInPersonTest extends TestCase
{
    public function testANameAndRoleAreAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        SignedInPerson::require('Ada', 'owner');
    }

    public function testAMissingNameOrRoleIsRefused(): void
    {
        foreach ([['','owner'], ['Ada', '']] as [$name, $role]) {
            try {
                SignedInPerson::require($name, $role);
                self::fail('a blank signer was accepted');
            } catch (DatabaseRefusal $error) {
                self::assertSame('the signed-in person is missing', $error->getMessage());
            }
        }
    }
}
