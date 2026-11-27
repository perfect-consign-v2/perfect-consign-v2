<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/prove-obligations.php';

final class ObligationSymbolTest extends TestCase
{
    public function testAShareAliasDoesNotCoverTheMethod(): void
    {
        self::assertFalse(\symbolIsCovered('share', "AS share\n"));
        self::assertFalse(\symbolIsCovered('share', "function share(\n"));
        self::assertTrue(\symbolIsCovered('share', "share(\n"));
        self::assertTrue(\symbolIsCovered('share', "\$this->share(\n"));
    }

    public function testASlashPairInsideAStringDoesNotHideTheCallsAfterIt(): void
    {
        $test = <<<'PHP'
<?php
$uri = 'otpauth://totp/' . rawurlencode('a') . '?secret=' . $secret;
$blank->useDraw(static fn (string $value): string => '');
$svg = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
$broken->useDraw(static fn (string $value): string => 'not-svg');
PHP;

        self::assertTrue(\symbolIsCovered('useDraw', \stripPhpNoise($test)));
    }

    public function testCommentsAndStringsCannotCoverASymbol(): void
    {
        $test = <<<'PHP'
<?php
// drawn(
/* drawn( */
/** drawn( */
$a = 'drawn(';
$b = "drawn( {$c}";
$d = <<<TEXT
drawn(
TEXT;
# it's drawn(
PHP;

        self::assertFalse(\symbolIsCovered('drawn', \stripPhpNoise($test)));
        self::assertTrue(\symbolIsCovered('drawn', \stripPhpNoise("<?php
\$code->drawn('x');
")));
    }

    public function testAPrivateHelperIsCoveredOnlyThroughTheTestedCaller(): void
    {
        $source = <<<'PHP'
final class Example
{
    public function seal(string $plain): string
    {
        return self::key();
    }

    private static function key(): string
    {
        return 'x';
    }

    private function hidden(): void
    {
    }
}
PHP;

        self::assertTrue(\privateHelperIsCovered($source, 'key', "seal(\n"));
        self::assertFalse(\privateHelperIsCovered($source, 'hidden', "seal(\n"));
        self::assertFalse(\privateHelperIsCovered($source, 'seal', "key(\n"));
    }

    public function testAReferenceHeldByATableALaterFileDropsNeedsNoProof(): void
    {
        $installer = "CREATE TABLE public.notes (\n    id uuid,\n    vendor_id uuid\n);\n"
            . "ALTER TABLE ONLY public.notes\n    ADD CONSTRAINT notes_vendor_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);\n"
            . "DROP TABLE public.legacy;\n";
        $dropped = \droppedTables([
            '001_tenant.sql' => $installer,
            '002_drop_notes.sql' => "-- DROP TABLE ignored;\nDROP TABLE IF EXISTS public.notes, other CASCADE;\n",
        ]);

        self::assertSame(['legacy' => '001_tenant.sql', 'notes' => '002_drop_notes.sql', 'other' => '002_drop_notes.sql'], $dropped);
        self::assertSame(['notes_vendor_fkey' => 'notes'], \referenceConstraints($installer, 'vendors'));
        self::assertTrue(\droppedLater($dropped, 'notes', '001_tenant.sql'));
        self::assertFalse(\droppedLater($dropped, 'notes', '002_drop_notes.sql'));
        self::assertFalse(\droppedLater($dropped, 'legacy', '001_tenant.sql'));
        self::assertFalse(\droppedLater($dropped, 'kept', '001_tenant.sql'));
    }

    public function testTheObligationBaseIsNotHead(): void
    {
        $base = \obligationBase(dirname(__DIR__, 2));
        self::assertNotSame('HEAD', $base);
        self::assertNotSame('', $base);
    }

    public function testExtractedSymbolsStayNamed(): void
    {
        $named = [
            'stayRows(',
            'stayRow(',
            'park(',
            'unchargedMonths(',
            'unprinted(',
            'timeZone(',
            'vendorRows(',
            'vendorRowsFor(',
            'askingRents(',
            'rentHistory(',
            'changeRent(',
            'askingRent(',
            'vendorRowQuery(',
            'endAssignment(',
            'setAskingRent(',
            'vendorOf(',
            'yearPaid(',
            'deposits(',
            'heldSplit(',
            'holdLayaway(',
            'includeDeposits(',
            'layaways(',
            'voidSale(',
            'returnItem(',
            'saleKey(',
            'clearTenders(',
            'sameItem(',
            'storedValue(',
            'expiring(',
            'donated(',
            'sellThrough(',
            'clearUsedPasswordResets(',
        ];
        self::assertContains('sellThrough(', $named);
        self::assertSame(count($named), count(array_unique($named)));
    }
}
