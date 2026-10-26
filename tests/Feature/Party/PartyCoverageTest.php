<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use InvalidArgumentException;
use PerfectConsign\Feature\Party\BoothMeasures;
use PerfectConsign\Feature\Party\MapStore;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Feature\Party\VendorMove;
use PerfectConsign\Feature\Party\VendorPlacement;
use PerfectConsign\Feature\Party\VendorValues;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

final class PartyCoverageTest extends TestCase
{
    public function testAVendorDraftRefusesBadFields(): void
    {
        $this->assertDraft('those notes are too long', ['notes' => str_repeat('n', 2001)]);
        $this->assertDraft('that tax id is too long', ['tax_id' => str_repeat('t', 41)]);
        $this->assertDraft('that address is too long', ['address' => str_repeat('a', 401)]);
        $this->assertDraft('Tax exempt number is required when tax exempt is enabled', [
            'tax_exempt' => '1',
            'tax_exempt_number' => '',
        ]);
        $this->assertDraft('a last name is required', ['last_name' => '']);
        $draft = new VendorDraft($this->fields(['code' => 'ada']), 2500);
        self::assertSame('ada', $draft->values()[0]);
    }

    public function testVendorValuesRefuseAMissingField(): void
    {
        $values = new VendorValues();
        try {
            $values->standing('maybe');
            self::fail('standing was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a vendor field is missing', $error->getMessage());
        }
        try {
            $values->optional(12);
            self::fail('a number was accepted as optional text');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a vendor field is missing', $error->getMessage());
        }
        self::assertFalse($values->standing('0'));
        self::assertSame('', $values->optional(null));
        self::assertNull($values->minor(null));
        self::assertSame(10, $values->minor('10'));
    }

    public function testPlacementNamesEndingAndIncomingStays(): void
    {
        $place = new VendorPlacement();
        $ending = [[
            'id' => 'a1',
            'booth_id' => 'b1',
            'booth_code' => 'A1',
            'starts_on' => '2020-01-01',
            'ends_on' => '2026-10-20',
            'rent_minor' => 1000,
        ]];
        self::assertSame('ending', $place->status($ending, '2026-10-15'));
        $incoming = [[
            'id' => 'a2',
            'booth_id' => 'b2',
            'booth_code' => 'B2',
            'starts_on' => '2026-11-01',
            'ends_on' => '',
            'rent_minor' => null,
        ]];
        self::assertSame('incoming', $place->status($incoming, '2026-10-15'));
        $open = [[
            'id' => 'a3',
            'booth_id' => 'b3',
            'booth_code' => 'C3',
            'starts_on' => '2020-01-01',
            'ends_on' => '',
            'rent_minor' => 500,
        ]];
        self::assertSame('active', $place->status($open, '2026-10-15'));
        self::assertSame('unassigned', $place->status([], '2026-10-15'));
    }

    public function testThePartyStoreRefusesAMissingRow(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => '']]);
        $statements->method('affected')->willReturn(0);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            return $work();
        });
        $stays = new \PerfectConsign\Feature\Party\AssignmentBook($statements);
        $store = new PartyStore($statements, QuietAudit::make($statements), $stays);
        $vendor = new VendorDraft($this->fields([]), null);
        try {
            $store->addVendor($vendor);
            self::fail('an empty insert was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('the vendor was not saved', $error->getMessage());
        }
        try {
            $store->saveVendor('00000000-0000-7000-8000-000000000001', $vendor);
            self::fail('a missing vendor was saved');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that vendor is not in this mall', $error->getMessage());
        }
        try {
            $store->setActive('00000000-0000-7000-8000-000000000001', false);
            self::fail('a missing vendor was made inactive');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that vendor is not in this mall', $error->getMessage());
        }
        try {
            $store->removeVendor('00000000-0000-7000-8000-000000000001');
            self::fail('a missing vendor was deleted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that vendor is not in this mall', $error->getMessage());
        }
        try {
            $store->saveBooth('00000000-0000-7000-8000-000000000001', 'A1', 'North', 10, 10);
            self::fail('a missing booth was saved');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that booth is not in this mall', $error->getMessage());
        }
        try {
            $store->removeBooth('00000000-0000-7000-8000-000000000001');
            self::fail('a missing booth was deleted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that booth is not in this mall', $error->getMessage());
        }
        try {
            $store->addBooth('A1', 'North', 10, 10, 1000);
            self::fail('an empty booth insert was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that booth was not created', $error->getMessage());
        }
        try {
            $stays->addAssignment(
                '00000000-0000-7000-8000-0000000000b1',
                '00000000-0000-7000-8000-0000000000a1',
                '2026-10-01',
                '',
                1000
            );
            self::fail('an empty assignment insert was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that assignment was not created', $error->getMessage());
        }
        $withId = $this->createStub(Statements::class);
        $withId->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            return $work();
        });
        $withId->method('bound')->willReturn([['id' => '00000000-0000-7000-8000-0000000000aa']]);
        try {
            (new \PerfectConsign\Feature\Party\AssignmentBook($withId))->addAssignment(
                '00000000-0000-7000-8000-0000000000b1',
                '00000000-0000-7000-8000-0000000000a1',
                '2026-10-01',
                '2026-10-31',
                null
            );
            self::fail('an assignment without rent was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that booth has no asking rent', $error->getMessage());
        }
    }

    public function testTheMapStoreRefusesMissingFloorsAndBooths(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([['total' => 20]]);
        $statements->method('affected')->willReturn(0);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            return $work();
        });
        $maps = new MapStore($statements);
        try {
            $maps->addFloor('Loft');
            self::fail('a 21st floor was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a mall keeps at most 20 floors', $error->getMessage());
        }
        try {
            $maps->renameFloor('00000000-0000-7000-8000-000000000001', 'Loft');
            self::fail('a missing floor was renamed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that floor is not in this mall', $error->getMessage());
        }
        try {
            $maps->removeFloor('00000000-0000-7000-8000-000000000001');
            self::fail('a missing floor was removed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that floor is not in this mall', $error->getMessage());
        }
        try {
            $maps->savePlacements([[
                'id' => '00000000-0000-7000-8000-0000000000b1',
                'floorId' => '00000000-0000-7000-8000-0000000000f1',
                'x' => 1,
                'z' => 1,
                'width' => 10,
                'depth' => 10,
            ]]);
            self::fail('a missing booth was placed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that booth is not in this mall', $error->getMessage());
        }
        $emptyInsert = $this->createStub(Statements::class);
        $emptyInsert->method('rows')->willReturn([['total' => 1]]);
        $emptyInsert->method('bound')->willReturn([['id' => '']]);
        try {
            (new MapStore($emptyInsert))->addFloor('Loft');
            self::fail('an empty floor insert was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('the floor was not created', $error->getMessage());
        }
    }

    public function testBoothMeasuresRefuseABadDay(): void
    {
        $measures = new BoothMeasures();
        try {
            $measures->rent([], 'October');
            self::fail('a rent day that is not a date was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent day is YYYY-MM-DD', $error->getMessage());
        }
        try {
            $measures->stays([['booth_id' => 'b1', 'vendor_id' => 'v1', 'vendor_name' => 'Ada', 'vendor_code' => 'ada']]);
            self::fail('a stay without a start was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a booth date is YYYY-MM-DD', $error->getMessage());
        }
    }

    public function testAMoveNeedsAnOpenStay(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            return $work();
        });
        $statements->method('bound')->willReturn([]);
        $move = new VendorMove($statements);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('That booth stay is already finished.');
        $move->move(
            '00000000-0000-7000-8000-0000000000a1',
            '00000000-0000-7000-8000-0000000000b1',
            '00000000-0000-7000-8000-0000000000c1',
            '2026-09-30',
            1000
        );
    }

    public function testPartyWritesKeepStringIdsAndOpenEndedAssignments(): void
    {
        $usedTransaction = false;
        $statements = $this->createStub(Statements::class);
        $statements->method('transaction')->willReturnCallback(static function (callable $work) use (&$usedTransaction): mixed {
            $usedTransaction = true;

            return $work();
        });
        $seen = ['sql' => '', 'count' => 0, 'id' => 4];
        $statements->method('bound')->willReturnCallback(static function (string $sql, array $params) use (&$seen): array {
            $seen = ['sql' => $sql, 'count' => count($params), 'id' => $seen['id']];

            return [['id' => $seen['id']]];
        });
        $stays = new \PerfectConsign\Feature\Party\AssignmentBook($statements);
        $store = new PartyStore($statements, QuietAudit::make($statements), $stays);
        try {
            $store->addVendor(new VendorDraft($this->fields([]), null));
            self::fail('a numeric vendor id was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('the vendor was not saved', $error->getMessage());
        }
        try {
            $store->addBooth('A1', 'North', 10, 10, null);
            self::fail('a numeric booth id was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that booth was not created', $error->getMessage());
        }
        self::assertFalse($usedTransaction);
        $seen['id'] = '00000000-0000-7000-8000-0000000000aa';
        $stays->addAssignment(
            '00000000-0000-7000-8000-0000000000b1',
            '00000000-0000-7000-8000-0000000000a1',
            '2026-10-01',
            '',
            1000
        );
        self::assertStringNotContainsString('ends_on', $seen['sql']);
        self::assertSame(3, $seen['count']);
        $stays->addAssignment(
            '00000000-0000-7000-8000-0000000000b1',
            '00000000-0000-7000-8000-0000000000a1',
            '2026-10-01',
            '2026-10-31',
            1000
        );
        self::assertStringContainsString('ends_on', $seen['sql']);
        self::assertSame(4, $seen['count']);
    }

    public function testAStayEndMustBeADate(): void
    {
        $measures = new BoothMeasures();
        try {
            $measures->stays([[
                'booth_id' => 'b1',
                'vendor_id' => 'v1',
                'vendor_name' => 'Ada',
                'vendor_code' => 'ada',
                'starts_on' => '2026-10-01',
                'ends_on' => 'not-a-date',
            ]]);
            self::fail('a stay end that is not a date was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a booth date is YYYY-MM-DD', $error->getMessage());
        }
        $open = $measures->stays([[
            'booth_id' => 'b1',
            'vendor_id' => 'v1',
            'vendor_name' => 'Ada',
            'vendor_code' => 'ada',
            'starts_on' => '2026-10-01',
            'ends_on' => '',
        ]]);
        self::assertNull($open[0]['endsOn']);
    }

    /**
     * @param array<string, string> $override
     */
    private function assertDraft(string $message, array $override): void
    {
        try {
            (new VendorDraft($this->fields($override), null))->values();
            self::fail($message . ' was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    /**
     * @param array<string, string> $override
     * @return array{
     *     code: string,
     *     first_name: string,
     *     last_name: string,
     *     phone: string,
     *     email: string,
     *     address: string,
     *     agreement_starts_on: string,
     *     agreement_ends_on: string,
     *     tax_id: string,
     *     notes: string,
     *     tax_exempt: string,
     *     tax_exempt_number: string
     * }
     */
    private function fields(array $override): array
    {
        return array_merge([
            'code' => 'ada',
            'first_name' => 'Ada',
            'last_name' => 'Lane',
            'phone' => '',
            'email' => '',
            'address' => '',
            'agreement_starts_on' => '',
            'agreement_ends_on' => '',
            'tax_id' => '',
            'notes' => '',
            'tax_exempt' => '',
            'tax_exempt_number' => '',
            'business_name' => '',
        ], $override);
    }
}
