<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use InvalidArgumentException;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

final class PartyStoreBranchesTest extends TestCase
{
    private const PARTY = '00000000-0000-7000-8000-0000000000p1';

    private const VENDOR = '00000000-0000-7000-8000-0000000000v1';

    private const BOOTH = '00000000-0000-7000-8000-0000000000b1';

    public function testListsAndBoothLookupsReturnTheRowsTheyWereGiven(): void
    {
        $vendorRows = [['id' => self::VENDOR, 'code' => 'ada']];
        $boothRows = [['id' => self::BOOTH, 'code' => 'A1']];
        $store = $this->store(
            static function (string $sql) use ($boothRows): array {
                if (str_contains($sql, 'FROM booths WHERE')) {
                    return $boothRows;
                }

                return [];
            },
            static function (string $sql) use ($vendorRows, $boothRows): array {
                if (str_contains($sql, 'FROM vendors')) {
                    return $vendorRows;
                }

                return $boothRows;
            },
        );

        self::assertSame($vendorRows, $store->vendors());
        self::assertSame($boothRows, $store->booths());
        self::assertSame($boothRows[0], $store->findBooth(self::BOOTH));
        self::assertNull($this->store(static fn (string $sql): array => [])->findBooth(self::BOOTH));
    }

    public function testFindPartySkipsBlankRolesAndVendorLookupRejectsABlankId(): void
    {
        $store = $this->store(static function (string $sql): array {
            if (str_contains($sql, 'FROM parties')) {
                return [[
                    'id' => self::PARTY,
                    'name' => 'Ada Lane',
                    'kind' => 'person',
                ]];
            }
            if (str_contains($sql, 'FROM party_roles')) {
                return [
                    ['role_type' => ''],
                    ['role_type' => 4],
                    ['role_type' => 'vendor'],
                ];
            }
            if (str_contains($sql, 'FROM vendors')) {
                return [['id' => self::VENDOR]];
            }

            return [];
        });

        self::assertSame([
            'id' => self::PARTY,
            'name' => 'Ada Lane',
            'kind' => 'person',
            'roles' => ['vendor'],
        ], $store->findParty(self::PARTY));
        self::assertSame(self::VENDOR, $store->vendorIdForParty(self::PARTY));
        self::assertNull($this->store(static fn (string $sql): array => [])->findParty(self::PARTY));
        self::assertNull($this->store(static fn (string $sql): array => [['id' => '']])->vendorIdForParty(self::PARTY));
        self::assertNull($this->store(static fn (string $sql): array => [['id' => 4]])->vendorIdForParty(self::PARTY));
    }

    public function testFindVendorKeepsExtraPhonesAndEmails(): void
    {
        $store = $this->store(static function (string $sql): array {
            if (str_contains($sql, 'FROM vendors')) {
                return [[
                    'id' => self::VENDOR,
                    'party_id' => self::PARTY,
                    'code' => 'ada',
                    'email' => 'ada@example.test',
                ]];
            }
            if (str_contains($sql, 'FROM party_contacts')) {
                return [
                    ['method' => 'phone', 'label' => 'primary', 'value' => '555-0100', 'is_primary' => true],
                    ['method' => 'phone', 'label' => 'mobile', 'value' => '555-0101', 'is_primary' => false],
                    ['method' => 'email', 'label' => 'work', 'value' => 'ada-work@example.test', 'is_primary' => 'f'],
                    ['method' => 'fax', 'label' => 'other', 'value' => '555-0199', 'is_primary' => false],
                ];
            }

            return [];
        });

        $vendor = $store->findVendor(self::VENDOR);
        self::assertIsArray($vendor);
        self::assertSame(['555-0101'], $vendor['phones']);
        self::assertSame(['ada-work@example.test'], $vendor['emails']);
    }

    public function testAVendorInsertBoothInsertAndPayoutModeKeepStringIds(): void
    {
        $usedTransaction = false;
        $payout = null;
        $store = $this->store(
            static function (string $sql): array {
                if (str_contains($sql, 'INSERT INTO vendors')) {
                    return [['id' => self::VENDOR]];
                }
                if (str_contains($sql, 'INSERT INTO booths')) {
                    return [['id' => self::BOOTH]];
                }

                return [];
            },
            static function (string $sql): array {
                if (str_contains($sql, 'INSERT INTO parties')) {
                    return [['id' => self::PARTY]];
                }

                return [];
            },
            static function (string $sql, array $params) use (&$payout): int {
                if (str_contains($sql, 'payout_mode')) {
                    $payout = $params;
                }

                return 1;
            },
            static function () use (&$usedTransaction): void {
                $usedTransaction = true;
            },
        );

        self::assertSame(self::VENDOR, $store->addVendor(new VendorDraft($this->fields(), null)));
        self::assertTrue($usedTransaction);
        $usedTransaction = false;
        $store->addBooth('A1', 'North', 10, 8, null);
        self::assertFalse($usedTransaction);
        $store->addBooth('A2', 'South', null, null, 2500);
        self::assertTrue($usedTransaction);
        $store->setPayoutMode(self::VENDOR, 'nope');
        self::assertSame(['pay', self::VENDOR], $payout);
        $store->setPayoutMode(self::VENDOR, 'hold');
        self::assertSame(['hold', self::VENDOR], $payout);
        $store->saveBooth(self::BOOTH, 'A1', 'North wing', 10, 8);
        $store->removeBooth(self::BOOTH);
        $store->removeVendor(self::VENDOR);
    }

    public function testPayoutModeRefusesAMissingVendor(): void
    {
        $store = $this->store(
            static fn (string $sql): array => [],
            static fn (string $sql): array => [],
            static fn (string $sql, array $params): int => 0,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('that vendor is not in this mall');
        $store->setPayoutMode(self::VENDOR, 'credit_only');
    }

    public function testSetActiveUpdatesTheRoleAndBumpsVendorSignInWhenTheyLeave(): void
    {
        $changes = [];
        $store = $this->activeStore(self::PARTY, $changes);
        $store->setActive(self::VENDOR, true);
        self::assertCount(1, $changes);
        self::assertStringContainsString('party_roles', $changes[0][0]);
        self::assertSame('active', $changes[0][1][0]);

        $changes = [];
        $leaving = $this->activeStore(self::PARTY, $changes);
        $leaving->setActive(self::VENDOR, false);
        self::assertCount(2, $changes);
        self::assertStringContainsString('UPDATE staff', $changes[1][0]);

        $changes = [];
        $blank = $this->activeStore('', $changes);
        $blank->setActive(self::VENDOR, false);
        self::assertCount(1, $changes);
        self::assertStringContainsString('UPDATE staff', $changes[0][0]);
    }

    public function testAddCustomerRoleReturnsTheParty(): void
    {
        $store = $this->store(static function (string $sql): array {
            if (str_contains($sql, 'FROM vendors')) {
                return [['id' => self::VENDOR, 'party_id' => self::PARTY, 'code' => 'ada']];
            }
            if (str_contains($sql, 'FROM party_roles')) {
                return [['id' => self::PARTY, 'status' => 'active']];
            }

            return [];
        });

        self::assertSame(self::PARTY, $store->addCustomerRole(self::VENDOR));
    }

    public function testAddCustomerRoleRefusesAMissingVendor(): void
    {
        $store = $this->store(static fn (string $sql): array => []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('that vendor is not in this mall');
        $store->addCustomerRole(self::VENDOR);
    }

    public function testSaveVendorRefusesWhenTheUpdateMatchesNothing(): void
    {
        $store = $this->store(
            static function (string $sql): array {
                if (str_contains($sql, 'FROM vendors')) {
                    return [[
                        'id' => self::VENDOR,
                        'party_id' => self::PARTY,
                        'code' => 'ada',
                        'email' => 'ada@example.test',
                    ]];
                }

                return [];
            },
            static fn (string $sql): array => [],
            static function (string $sql): int {
                if (str_contains($sql, 'UPDATE vendors')) {
                    return 0;
                }

                return 1;
            },
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('that vendor is not in this mall');
        $store->saveVendor(self::VENDOR, new VendorDraft($this->fields(), null));
    }

    /**
     * @param list<array{0: string, 1: array<int, mixed>}> $changes
     */
    private function activeStore(string $partyId, array &$changes): PartyStore
    {
        return $this->store(
            static function (string $sql) use ($partyId): array {
                if (str_contains($sql, 'FROM vendors')) {
                    return [[
                        'id' => self::VENDOR,
                        'party_id' => $partyId,
                        'code' => 'ada',
                        'email' => null,
                    ]];
                }

                return [];
            },
            static fn (string $sql): array => [],
            static fn (string $sql, array $params): int => 1,
            null,
            static function (string $sql, array $params) use (&$changes): void {
                $changes[] = [$sql, $params];
            },
        );
    }

    private function store(
        callable $bound,
        ?callable $rows = null,
        ?callable $affected = null,
        ?callable $onTransaction = null,
        ?callable $change = null,
    ): PartyStore {
        $statements = $this->createStub(Statements::class);
        $statements->method('transaction')->willReturnCallback(
            static function (callable $work) use ($onTransaction): void {
                if ($onTransaction !== null) {
                    $onTransaction();
                }
                $work();
            }
        );
        $statements->method('bound')->willReturnCallback(
            static function (string $sql, array $params) use ($bound): array {
                self::assertIsArray($params);

                return $bound($sql);
            }
        );
        $statements->method('rows')->willReturnCallback(
            static function (string $sql) use ($rows): array {
                return $rows === null ? [] : $rows($sql);
            }
        );
        $statements->method('affected')->willReturnCallback(
            static function (string $sql, array $params) use ($affected): int {
                self::assertIsArray($params);

                return $affected === null ? 1 : $affected($sql, $params);
            }
        );
        $statements->method('change')->willReturnCallback(
            static function (string $sql, array $params) use ($change): void {
                if ($change !== null) {
                    $change($sql, $params);
                }
            }
        );

        return new PartyStore(
            $statements,
            QuietAudit::make($statements),
            new AssignmentBook($statements),
            new PartyIdentity($statements),
        );
    }

    /**
     * @return array{
     *     code: string,
     *     first_name: string,
     *     last_name: string,
     *     phone: string,
     *     email: string,
     *     address: string,
     *     city: string,
     *     state: string,
     *     postal: string,
     *     agreement_starts_on: string,
     *     agreement_ends_on: string,
     *     tax_id: string,
     *     notes: string,
     *     tax_exempt: string,
     *     tax_exempt_number: string,
     *     business_name: string
     * }
     */
    private function fields(): array
    {
        return [
            'code' => 'ada',
            'first_name' => 'Ada',
            'last_name' => 'Lane',
            'phone' => '',
            'email' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'postal' => '',
            'agreement_starts_on' => '',
            'agreement_ends_on' => '',
            'tax_id' => '',
            'notes' => '',
            'tax_exempt' => '',
            'tax_exempt_number' => '',
            'business_name' => '',
        ];
    }
}
