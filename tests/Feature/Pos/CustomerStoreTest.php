<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Pos\CustomerStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CustomerStoreTest extends TestCase
{
    private const PARTY = '00000000-0000-7000-8000-0000000000c1';

    private const VENDOR = '00000000-0000-7000-8000-0000000000c2';

    public function testAMatchingCustomerIsReused(): void
    {
        $store = $this->store(static function (string $sql): array {
            if (str_contains($sql, 'stall_party_display_name')) {
                return [['id' => self::PARTY]];
            }

            return [];
        });

        self::assertSame(self::PARTY, $store->findOrCreate('Ada Lane', '555-0100', 'ada@example.test'));
    }

    public function testANewCustomerIsAPersonWithOrWithoutContact(): void
    {
        $changes = [];
        $store = $this->store(
            static fn (string $sql): array => [],
            static function (string $sql): array {
                if (str_contains($sql, 'INSERT INTO parties')) {
                    return [['id' => self::PARTY]];
                }

                return [];
            },
            static function (string $sql, array $params) use (&$changes): void {
                $changes[] = [$sql, $params];
            },
        );

        self::assertSame(self::PARTY, $store->findOrCreate('Ada', '', ''));
        self::assertSame(self::PARTY, $store->findOrCreate('Ada Lane', '(555) 010-0000', 'ada@example.test'));
        $personInserts = array_values(array_filter(
            $changes,
            static fn (array $call): bool => str_contains($call[0], 'INSERT INTO persons')
        ));
        self::assertSame('Ada', $personInserts[0][1][3]);
        self::assertNull($personInserts[1][1][3]);
    }

    public function testNamesPhonesAndEmailsAreRefusedBeforeAQuery(): void
    {
        $store = $this->store(static fn (string $sql): array => []);
        $this->assertRefusal('Enter the customer name.', static fn () => $store->findOrCreate('', '', ''));
        $this->assertRefusal('Enter the customer name.', static fn () => $store->findOrCreate(str_repeat('n', 121), '', ''));
        $this->assertRefusal('That phone number is too long.', static fn () => $store->findOrCreate('Ada', str_repeat('x', 41), ''));
        $this->assertRefusal(
            'Enter a real email or leave it blank.',
            static fn () => $store->findOrCreate('Ada', '', str_repeat('a', 190) . '@example.test')
        );
        $this->assertRefusal('Enter a real email or leave it blank.', static fn () => $store->findOrCreate('Ada', '', 'not-an-email'));
    }

    public function testACustomerRecordCanBeRewritten(): void
    {
        $updates = [];
        $store = $this->store(
            static function (string $sql): array {
                if (str_contains($sql, "status = 'active'")) {
                    return [['ok' => 1]];
                }
                if (str_contains($sql, 'FROM persons')) {
                    return [['party_id' => self::PARTY]];
                }
                if (str_contains($sql, 'FROM party_addresses')) {
                    return [[
                        'street' => '1 Main',
                        'street2' => null,
                        'city' => 'Bend',
                        'state' => 'OR',
                        'postal' => '97701',
                    ]];
                }

                return [];
            },
            static fn (string $sql): array => [],
            static function (string $sql, array $params): void {
            },
            static function (string $sql, array $params) use (&$updates): int {
                $updates[] = [$sql, $params];

                return 1;
            },
        );

        $store->save(self::PARTY, 'Ada Lane', '', '');
        $store->save(self::PARTY, 'Ada', '555-0100', 'ada@example.test');
        self::assertNull($updates[0][1][2]);
        self::assertSame('Ada', $updates[1][1][2]);
    }

    public function testASaveWithoutAnAddressUsesEmptyPlaceFields(): void
    {
        $updates = [];
        $store = $this->store(
            static function (string $sql): array {
                if (str_contains($sql, "status = 'active'")) {
                    return [['ok' => 1]];
                }
                if (str_contains($sql, 'FROM persons')) {
                    return [['party_id' => self::PARTY]];
                }

                return [];
            },
            static fn (string $sql): array => [],
            static function (string $sql, array $params): void {
            },
            static function (string $sql, array $params) use (&$updates): int {
                $updates[] = $params;

                return 1;
            },
        );

        $store->save(self::PARTY, 'Ada Lane Shop', '', '');
        self::assertSame('Ada', $updates[0][0]);
        self::assertSame('Lane Shop', $updates[0][1]);
        self::assertNull($updates[0][2]);
    }

    public function testSaveRefusesAMissingCustomerAndAnOrganization(): void
    {
        $missing = $this->store(static fn (string $sql): array => []);
        $this->assertRefusal('that customer is not in this mall', static fn () => $missing->save(self::PARTY, 'Ada', '', ''));

        $organization = $this->store(static function (string $sql): array {
            if (str_contains($sql, "status = 'active'")) {
                return [['ok' => 1]];
            }

            return [];
        });
        $this->assertRefusal(
            'Organization customers cannot be edited as a person name yet.',
            static fn () => $organization->save(self::PARTY, 'Ada', '', '')
        );
    }

    public function testFindReadsACustomerAndIgnoresABadRow(): void
    {
        $found = $this->store(static function (string $sql): array {
            return [[
                'id' => self::PARTY,
                'name' => 'Ada Lane',
                'phone' => '555-0100',
                'email' => 'ada@example.test',
            ]];
        });
        self::assertSame([
            'id' => self::PARTY,
            'name' => 'Ada Lane',
            'phone' => '555-0100',
            'email' => 'ada@example.test',
        ], $found->find(self::PARTY));

        $empty = $this->store(static fn (string $sql): array => []);
        self::assertNull($empty->find(self::PARTY));
        $broken = $this->store(static fn (string $sql): array => [null]);
        self::assertNull($broken->find(self::PARTY));
    }

    public function testACustomerBecomesAVendorOrKeepsTheVendorTheyAlreadyHave(): void
    {
        $created = $this->store(static function (string $sql): array {
            if (str_contains($sql, 'stall_party_display_name')) {
                return [[
                    'id' => self::PARTY,
                    'name' => 'Ada',
                    'phone' => '',
                    'email' => '',
                ]];
            }
            if (str_contains($sql, 'FROM persons')) {
                return [['first_name' => 'Ada', 'last_name' => 'Ada', 'display_name' => null]];
            }
            if (str_contains($sql, 'FROM party_roles')) {
                return [['id' => self::PARTY, 'status' => 'active']];
            }
            if (str_contains($sql, 'INSERT INTO vendors')) {
                return [['id' => self::VENDOR]];
            }

            return [];
        });
        self::assertSame(self::VENDOR, $created->addVendorRole(self::PARTY, 'ada'));

        $existing = $this->store(static function (string $sql): array {
            if (str_contains($sql, 'stall_party_display_name')) {
                return [[
                    'id' => self::PARTY,
                    'name' => 'Ada',
                    'phone' => '',
                    'email' => '',
                ]];
            }
            if (str_contains($sql, 'FROM persons')) {
                return [['first_name' => 'Ada', 'last_name' => 'Ada', 'display_name' => null]];
            }
            if (str_contains($sql, 'FROM vendors')) {
                return [['id' => self::VENDOR]];
            }
            if (str_contains($sql, 'FROM party_roles')) {
                return [['id' => self::PARTY, 'status' => 'inactive']];
            }

            return [];
        });
        self::assertSame(self::VENDOR, $existing->addVendorRole(self::PARTY, 'ada'));
    }

    public function testAVendorRoleRefusesAMissingCustomerAndAnOrganization(): void
    {
        $missing = $this->store(static fn (string $sql): array => []);
        $this->assertRefusal('that customer is not in this mall', static fn () => $missing->addVendorRole(self::PARTY, 'ada'));

        $organization = $this->store(static function (string $sql): array {
            if (str_contains($sql, 'stall_party_display_name')) {
                return [[
                    'id' => self::PARTY,
                    'name' => 'Lane Goods',
                    'phone' => '',
                    'email' => '',
                ]];
            }

            return [];
        });
        $this->assertRefusal(
            'Organization customers cannot be promoted to vendor.',
            static fn () => $organization->addVendorRole(self::PARTY, 'lane')
        );
    }

    public function testASpacedNameWithAnEmptySideStaysWhole(): void
    {
        $store = $this->store(static fn (string $sql): array => []);
        $names = new ReflectionMethod(CustomerStore::class, 'personNames');

        self::assertSame(['Ada ', 'Ada '], $names->invoke($store, 'Ada '));
    }

    private function assertRefusal(string $message, callable $call): void
    {
        try {
            $call();
            self::fail($message . ' was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    private function store(
        callable $bound,
        ?callable $rows = null,
        ?callable $change = null,
        ?callable $affected = null,
    ): CustomerStore {
        $statements = $this->createStub(Statements::class);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): void {
            $work();
        });
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
        $statements->method('change')->willReturnCallback(
            static function (string $sql, array $params) use ($change): void {
                if ($change !== null) {
                    $change($sql, $params);
                }
            }
        );
        $statements->method('affected')->willReturnCallback(
            static function (string $sql, array $params) use ($affected): int {
                return $affected === null ? 1 : $affected($sql, $params);
            }
        );

        return new CustomerStore($statements, new PartyIdentity($statements));
    }
}
