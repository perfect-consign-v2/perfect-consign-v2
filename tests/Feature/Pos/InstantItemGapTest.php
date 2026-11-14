<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Inventory\IntakeCatalog;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemWriter;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Pos\InstantAdd;
use PerfectConsign\Feature\Pos\InstantBrand;
use PerfectConsign\Feature\Pos\InstantDiscount;
use PerfectConsign\Feature\Pos\InstantItem;
use PerfectConsign\Feature\Pos\TicketBuyer;
use PerfectConsign\Feature\Pos\TicketCustomer;
use PerfectConsign\Feature\Pos\TicketDrafts;
use PerfectConsign\Feature\Pos\TicketLines;
use PerfectConsign\Feature\Pos\TicketPriceChange;
use PerfectConsign\Feature\Pos\TicketPricing;
use PerfectConsign\Feature\Pos\TicketQueue;
use PerfectConsign\Feature\Pos\TicketStore;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Pos\CustomerStore;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class InstantItemGapTest extends TestCase
{
    private const VENDOR = MallDoor::VENDOR;

    public function testDiscardDraftRemovesTheParkedLine(): void
    {
        [$item, $statements] = $this->item(false);
        $item->discardDraft('session', 3);
        self::assertSame(1, $statements->draftDeletes);
    }

    public function testAddRefusesAVendorDescriptionAndPrice(): void
    {
        [$item] = $this->item(false);
        $this->expectInvalid($item, $this->add('nope', 'Hat', '2.00', '1', ''), 'Choose a vendor.');
        $this->expectInvalid($item, $this->add(self::VENDOR, '   ', '2.00', '1', ''), 'Enter a description.');
        $this->expectInvalid($item, $this->add(self::VENDOR, 'Hat', '   ', '1', ''), 'Enter a price.');
    }

    public function testAMissingTicketLineIsRefused(): void
    {
        [$item] = $this->item(true);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That item is not on the ticket.');
        $item->add('session', '2026-11-12', $this->add(self::VENDOR, 'Hat', '2.50', '1', ''));
    }

    public function testAnEmptyLineDiscountIsRefused(): void
    {
        [$item] = $this->item(false);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Enter a line discount.');
        $item->add('session', '2026-11-12', $this->add(self::VENDOR, 'Hat', '2.50', '1', 'percent', ''));
    }

    public function testAZeroLineDiscountIsSkipped(): void
    {
        [$item, $statements] = $this->item(false);
        $item->add('session', '2026-11-12', $this->add(self::VENDOR, 'Hat', '2.50', '1', 'percent', '0'));
        self::assertSame(0, $statements->lineDiscounts);
        self::assertSame(0, $statements->quantityWrites);
    }

    public function testAPercentDiscountAndExtraQuantityLandOnTheTicket(): void
    {
        [$item, $statements] = $this->item(false);
        $item->add('session', '2026-11-12', $this->add(self::VENDOR, 'Hat', '2.50', '2', 'percent', '10'));
        self::assertSame(1, $statements->lineDiscounts);
        self::assertSame(1, $statements->quantityWrites);
    }

    public function testAFlatDiscountPostsAsAnAmount(): void
    {
        [$item, $statements] = $this->item(false);
        $item->add('session', '2026-11-12', $this->add(self::VENDOR, 'Hat', '2.50', '1', 'flat', '1.50'));
        self::assertSame(1, $statements->lineDiscounts);
        self::assertSame(0, $statements->quantityWrites);
    }

    private function expectInvalid(InstantItem $item, InstantAdd $add, string $message): void
    {
        try {
            $item->add('session', '2026-11-12', $add);
            self::fail($message);
        } catch (\InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    private function add(
        string $vendorId,
        string $description,
        string $price,
        string $quantity,
        string $kind,
        string $value = '',
    ): InstantAdd {
        return new InstantAdd(
            $vendorId,
            $description,
            $price,
            '',
            $quantity,
            new InstantBrand('', ''),
            [],
            new InstantDiscount($kind, $value),
        );
    }

    /**
     * @return array{InstantItem, InstantStatements}
     */
    private function item(bool $missLine): array
    {
        $statements = new InstantStatements($this->createStub(PDO::class), $missLine);
        $mall = new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($this->createStub(PDO::class)), $this->createStub(PDO::class), new CompanyLines()),
            new MallDay($statements),
        );
        $pricing = new TicketPricing($statements);
        $session = $this->createStub(Session::class);
        $actor = new Actor($session);
        $ceiling = new SaleCeiling($statements, $actor, new AuditLog($statements), new Request('GET', '/gapmall/pos', [], []));
        $tickets = new TicketStore(
            $statements,
            $pricing,
            new TicketBuyer($statements, $mall),
            new TicketQueue($statements, $pricing),
            new TicketCustomer($statements, new CustomerStore($statements, new PartyIdentity($statements))),
            $ceiling,
            new TicketPriceChange($statements, $pricing, $actor, new AuditLog($statements)),
        );

        return [new InstantItem(
            new ItemWriter(new IntakeCatalog($statements), new NameListStore($statements, new CatalogLinks($statements)), $mall, new StoreTake()),
            new InventoryStore($statements, new CatalogLinks($statements), $mall),
            $tickets,
            new TicketLines($statements, $pricing),
            new TicketDrafts($statements),
            $ceiling,
            $mall,
        ), $statements];
    }
}

final class InstantStatements extends Statements
{
    public int $draftDeletes = 0;

    public int $quantityWrites = 0;

    public int $lineDiscounts = 0;

    private string $code = '';

    public function __construct(PDO $pdo, private readonly bool $missLine)
    {
        parent::__construct($pdo);
    }

    /**
     * @param list<mixed> $params
     */
    public function change(string $sql, array $params): void
    {
        if (str_contains($sql, 'DELETE FROM ticket_draft_lines')) {
            $this->draftDeletes++;
        }
    }

    /**
     * @param list<mixed> $params
     */
    public function affected(string $sql, array $params): int
    {
        if (str_contains($sql, 'SET quantity')) {
            $this->quantityWrites++;
        }
        if (str_contains($sql, 'line_discount_kind')) {
            $this->lineDiscounts++;
        }

        return 1;
    }

    public function transaction(callable $work): void
    {
        $work();
    }

    public function isolated(callable $work): void
    {
        $work();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql): array
    {
        if (str_contains($sql, 'FROM mall_sales')) {
            return [];
        }
        if (str_contains($sql, 'booth_width_ft')) {
            return [[
                'time_zone' => 'America/Los_Angeles',
                'booth_width_ft' => 10,
                'booth_depth_ft' => 10,
                'floor_width_ft' => 40,
                'floor_depth_ft' => 40,
                'commission_bps' => 4000,
                'floor_days' => 60,
                'cash_round_minor' => 0,
                'tax_bps' => 0,
                'use_color' => false,
                'use_category' => false,
                'use_size' => false,
                'use_condition' => false,
                'use_department' => false,
                'use_brand' => false,
                'label_stock' => 'avery_8160',
                'booth_rentals_enabled' => false,
                'rent_cycle_day' => 1,
                'consignment_enabled' => true,
                'store_stock_enabled' => false,
                'receipt_footer' => '',
                'operating_mode' => 'both',
            ]];
        }
        if (str_contains($sql, 'SELECT time_zone FROM mall')) {
            return [['time_zone' => 'America/Los_Angeles']];
        }
        if (str_contains($sql, 'tax_bps, cash_round_minor')) {
            return [['tax_bps' => 0, 'cash_round_minor' => 0]];
        }

        return [];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function bound(string $sql, array $params): array
    {
        if (str_contains($sql, 'FROM vendors WHERE id')) {
            return [['id' => MallDoor::VENDOR]];
        }
        if (str_contains($sql, 'AS mark')) {
            return [];
        }
        if (str_contains($sql, 'INSERT INTO items')) {
            return [['id' => '01900000-0000-7000-8000-0000000000i1']];
        }
        if (str_contains($sql, 'price_minor FROM items')) {
            $code = $params[0] ?? '';
            $this->code = is_string($code) ? $code : '';

            return [['id' => '01900000-0000-7000-8000-0000000000i1', 'price_minor' => 250]];
        }
        if (str_contains($sql, 'SELECT id FROM tickets')) {
            return [['id' => '01900000-0000-7000-8000-0000000000t1']];
        }
        if (str_contains($sql, 'SELECT posting_key FROM tickets')) {
            return [['posting_key' => 'instant-post']];
        }
        if (str_contains($sql, 'count(*) AS lines')) {
            return [['lines' => 1]];
        }
        if (str_contains($sql, 'MAX(line_no)')) {
            return [['line_no' => 1]];
        }
        if (str_contains($sql, 'line.line_no, line.item_id')) {
            return [[
                'line_no' => 1,
                'item_id' => '01900000-0000-7000-8000-0000000000i1',
                'price_minor' => 250,
                'quantity' => 1,
                'line_discount_minor' => 0,
                'offer_discount_minor' => 0,
                'store_discount_minor' => 0,
                'offer_name' => '',
                'code' => $this->missLine ? 'nope' : $this->code,
                'description' => 'Hat',
                'vendor_id' => MallDoor::VENDOR,
                'placement' => 'consigned',
                'cost_minor' => 0,
                'vendor_name' => 'Ada',
                'tax_exempt' => false,
                'line_discount_kind' => '',
                'line_discount_value' => null,
                'line_discount_reason' => '',
                'commission_bps' => 0,
            ]];
        }
        if (str_contains($sql, 'stall_line_discount')) {
            return [['share' => 0]];
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(PDOStatement $statement): array
    {
        return [];
    }
}
