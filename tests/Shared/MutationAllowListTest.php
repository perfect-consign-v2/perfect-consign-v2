<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PHPUnit\Framework\TestCase;

final class MutationAllowListTest extends TestCase
{
    public function testALinuxCoveragePathMatchesTheAllowList(): void
    {
        putenv('PC_MUTATE_FUNCTIONS=1');
        require_once dirname(__DIR__, 2) . '/bin/mutate.php';
        putenv('PC_MUTATE_FUNCTIONS');

        $linux = relative('/workspace/repo', '/workspace/repo/src/Feature/Rent/RentFigure.php');
        self::assertSame('src\\Feature\\Rent\\RentFigure.php', $linux);
        self::assertNotNull(equivalentReason($linux, 47, '&&', '||'));
        self::assertNotNull(equivalentReason($linux, 47, '>', '>='));
        self::assertNotNull(equivalentReason('src\\Shared\\Mail\\SmtpClient.php', 41, '||', '&&'));
        self::assertNotNull(equivalentReason('src\\Shared\\Mail\\SmtpClient.php', 46, '||', '&&'));
        self::assertNotNull(equivalentReason('src\\Feature\\Mall\\settings.php', 14, '>=', '>'));
        self::assertNotNull(equivalentReason('src\\Feature\\Mall\\MallPayoutSettings.php', 160, '>=', '>'));
        self::assertNotNull(equivalentReason('src\\Feature\\Vendor\\VendorCheck.php', 70, '>', '>='));
        self::assertNotNull(equivalentReason('src\\Feature\\Vendor\\VendorCheck.php', 126, '>', '>='));
        self::assertNotNull(equivalentReason('src\\Feature\\Pos\\SaleJournal.php', 122, '>', '>='));
        self::assertNotNull(equivalentReason('src\\Feature\\Pos\\SaleJournal.php', 139, '>', '>='));
        self::assertNotNull(equivalentReason('src\\Feature\\Rent\\LateFeeDue.php', 84, '<', '<='));
        self::assertNotNull(equivalentReason('src\\Feature\\Pos\\TicketPricing.php', 182, '>', '>='));
        self::assertNotNull(equivalentReason('src\\Feature\\Book\\MissedSaleQuote.php', 17, '>', '>='));
        self::assertNotNull(equivalentReason('src\\Shared\\Web\\layout.php', 244, '&&', '||'));
        self::assertNotNull(equivalentReason('src\\Feature\\Pos\\pos.php', 96, '>', '>='));
        self::assertNotNull(equivalentReason('src\\Feature\\Vendor\\VendorFigures.php', 61, '!', ''));
        self::assertNotNull(equivalentReason('src\\Feature\\Password\\LoginAccounts.php', 79, '!', ''));

        $windows = relative(
            'C:\\laragon\\www\\perfect-consign',
            'C:\\laragon\\www\\perfect-consign\\src\\Feature\\Rent\\RentFigure.php'
        );
        self::assertSame('src\\Feature\\Rent\\RentFigure.php', $windows);
        self::assertNotNull(equivalentReason($windows, 47, '&&', '||'));
    }

    public function testARunThatMutatesNothingIsRefused(): void
    {
        $this->loadHarness();
        self::assertFalse(harnessWasInvoked());
        self::assertSame(313, mutantMinimum());
        self::assertNotNull(tooFewMutants(0));
        self::assertNotNull(tooFewMutants(312));
        self::assertNull(tooFewMutants(313));

        $root = dirname(__DIR__, 2);
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['PC_MUTATE_FUNCTIONS'] = '1';
        $process = proc_open(
            [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mutate.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $env
        );
        self::assertIsResource($process);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        self::assertSame(1, $code);
        self::assertIsString($error);
        self::assertStringContainsString('PC_MUTATE_FUNCTIONS', $error);
    }

    private function loadHarness(): void
    {
        putenv('PC_MUTATE_FUNCTIONS=1');
        require_once dirname(__DIR__, 2) . '/bin/mutate.php';
        putenv('PC_MUTATE_FUNCTIONS');
    }
}
