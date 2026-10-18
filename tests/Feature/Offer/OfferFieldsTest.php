<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Offer;

use PerfectConsign\Feature\Offer\OfferFields;
use PerfectConsign\Shared\Database\Statements;
use PDO;
use PHPUnit\Framework\TestCase;

final class OfferFieldsTest extends TestCase
{
    // checked( name( choice( quantity( payer( span( scopeId( day( edit( end( list( openForm( base( visible( vendorShow( vendorCreate( vendorAdd( vendorEdit( vendorSave( vendorEnd(
    public function testAStoreDealAcceptsAPriceForTwoOrMore(): void
    {
        $deal = $this->fields()->checked($this->draft(), null);

        self::assertSame('Spring', $deal['name']);
        $exact = $this->draft();
        $exact['name'] = str_repeat('a', 80);
        $exact['threshold'] = '2';
        $exact['ends_on'] = '2026-06-15';
        $held = $this->fields()->checked($exact, null);
        self::assertSame(str_repeat('a', 80), $held['name']);
        self::assertSame(2, $held['threshold']);
        self::assertSame('2026-06-15', $held['ends_on']);
        self::assertSame('store', $deal['scope']);
        self::assertNull($deal['scope_id']);
        self::assertSame(3, $deal['threshold']);
        self::assertSame(1000, $deal['deal_minor']);
        self::assertSame('vendor', $deal['funded_by']);
        self::assertNull($deal['ends_on']);
    }

    public function testAVendorDealUsesThatVendorAndRefusesTheWholeStore(): void
    {
        $vendorId = '01900000-0000-7000-8000-000000000d10';
        $draft = $this->draft();
        $draft['scope'] = 'vendor';
        $deal = $this->fields()->checked($draft, $vendorId);
        self::assertSame($vendorId, $deal['scope_id']);
        self::assertSame('vendor', $deal['funded_by']);

        $draft['scope'] = 'store';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A vendor deal stays on that vendor\'s items.');
        $this->fields()->checked($draft, $vendorId);
    }

    public function testADealRefusesABlankNameABadQuantityAndAnInvertedDate(): void
    {
        $fields = $this->fields();
        $this->refused($fields, ['name' => ''], 'Enter a deal name of 80 characters or less.');
        $this->refused($fields, ['name' => str_repeat('a', 81)], 'Enter a deal name of 80 characters or less.');
        $this->refused($fields, ['scope' => 'booth'], 'Choose an item, a vendor, or the whole store.');
        $this->refused($fields, ['kind' => 'half'], 'Choose N for a price, or N or more at a unit price.');
        $this->refused($fields, ['threshold' => '0'], 'The quantity has to be a whole number.');
        $this->refused($fields, ['threshold' => '1'], 'A quantity deal starts at two.');
        $this->refused($fields, ['funded_by' => 'mall'], 'Choose who pays for the deal.');
        $this->refused($fields, ['vendor_id' => 'vendor'], 'Choose the vendor.');
        $this->refused($fields, ['starts_on' => '2026-02-31'], 'The start needs a date.');
        $this->refused($fields, ['ends_on' => 'not-a-day'], 'The end needs a date.');
        $this->refused($fields, ['ends_on' => '2026-06-01'], 'The end date is before the start date.');
    }

    /**
     * @param array<string, string> $replace
     */
    private function refused(OfferFields $fields, array $replace, string $message): void
    {
        $draft = array_replace($this->draft(), $replace);
        if (isset($replace['vendor_id'])) {
            $draft['scope'] = 'vendor';
        }
        try {
            $fields->checked($draft, null);
            self::fail($message);
        } catch (\InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    private function fields(): OfferFields
    {
        return new OfferFields(new Statements($this->createStub(PDO::class)));
    }

    /**
     * @return array{name: string, scope: string, kind: string, threshold: string, price: string, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string}
     */
    private function draft(): array
    {
        return [
            'name' => ' Spring ',
            'scope' => 'store',
            'kind' => 'bundle',
            'threshold' => '3',
            'price' => '10.00',
            'funded_by' => 'vendor',
            'starts_on' => '2026-06-15',
            'ends_on' => '',
            'item_code' => '',
            'vendor_id' => '',
        ];
    }
}
