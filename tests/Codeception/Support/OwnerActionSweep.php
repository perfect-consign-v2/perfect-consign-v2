<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Support;

use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Bootstrap\RouteInventory;
use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Feature\Vendor\VendorEntry;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\PageResponse;
use PerfectConsign\Tests\Support\ProbeMall;
use RuntimeException;

/**
 * Every mall route an owner can open, with the sentence that screen returns.
 */
final class OwnerActionSweep
{
    private const DATE = ProbeMall::POSTING_DATE;

    /** After the latest settled payout window on the probe mall (October 2026). */
    private const STAY_START = '2026-11-01';

    private const FIRST_STAY_END = '2026-11-05';

    private const NEXT_STAY_START = '2026-11-06';

    private const RENT_ON = '2026-11-06';

    private const STAY_END = '2026-11-10';

    private OwnerActionHttp $http;

    private string $vendorId = '';

    private string $vendorCode = '';

    private string $otherVendorId = '';

    private string $customerId = '';

    private string $boothId = '';

    private string $otherBoothId = '';

    private string $saleCode = '';

    private string $saleItemId = '';

    private string $voidCode = '';

    private string $voidItemId = '';

    private string $layawayCode = '';

    private string $payoutCode = '';

    private string $payoutItemId = '';

    private string $extraCode = '';

    private string $taxCode = '';

    private string $useCode = '';

    private string $missedCode = '';

    private string $registerId = '';

    private string $today = self::DATE;

    public function __construct(
        private readonly FormClient $client,
        private readonly string $slug,
    ) {
        $this->http = new OwnerActionHttp($client, $slug);
    }

    /**
     * @return array{failures: list<string>, hits: list<array{method: string, path: string}>}
     */
    public function run(): array
    {
        $home = $this->client->signIn($this->slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $this->http->fetch('GET', '/' . $this->slug . '/dashboard', null, ["Today's sales"], 90, false);
        if (!str_contains($home->body, "Today's sales")) {
            $this->http->failures[] = 'owner sign-in did not open the mall. ' . HtmlPage::error($home->body);

            return $this->result();
        }
        $this->client->ensurePeriod($this->slug);
        $this->building();
        $this->money();
        $this->openDrawer();
        $this->pages();
        $this->people();
        $this->catalogs();
        $this->boothsAndRent();
        $this->inventory();
        $this->countsAndCloseOut();
        $this->badDebts();
        $this->registerDesk();
        $this->books();
        $this->storefront();
        $this->registers();
        $this->staff();
        $this->payouts();
        $this->vendorPortal();
        $this->doors();
        $this->password();
        $this->signOut();

        return $this->result();
    }

    private function building(): void
    {
        $this->http->post('/settings/building', [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'floor_days' => '30',
            'consignment_enabled' => '1',
            'label_stock' => 'avery_8160',
            'use_color' => '1',
            'use_category' => '1',
            'use_size' => '1',
            'use_condition' => '1',
            'use_department' => '1',
            'use_brand' => '1',
            'vendor_portal_enabled' => '1',
            'vendor_inventory_enabled' => '1',
        ], ['Building saved.']);
    }

    private function openDrawer(): void
    {
        $page = $this->http->get('/registers', ['Register'], false, 180);
        if (str_contains($page->body, 'Close register')) {
            $this->registerId = $this->http->id($page->body, '/data-open-modal="close-([0-9a-fA-F-]{36})"/', 'open register');
            $this->useRegister($page->body);

            return;
        }
        $action = $this->http->id($page->body, '#/registers/([0-9a-fA-F-]{36})/open#', 'register to open');
        if ($action === '') {
            return;
        }
        $this->registerId = $action;
        $opened = $this->http->post('/registers/' . $action . '/open', [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '0.00',
            ...$this->zeroCounts(),
        ], ['Register opened.', 'Close register']);
        if ($this->registerId === '' && preg_match('/data-open-modal="close-([0-9a-fA-F-]{36})"/', $opened->body, $match) === 1) {
            $this->registerId = strtolower($match[1]);
        }
        $this->useRegister($opened->body);
    }

    private function useRegister(string $html): void
    {
        if ($this->registerId === '') {
            return;
        }
        $dated = $this->input($html, 'drop-date-' . $this->registerId);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dated) === 1) {
            $this->today = $dated;
        }
        $this->http->post('/registers/' . $this->registerId . '/use', [], ['is the active register.']);
    }

    private function pages(): void
    {
        $root = dirname(__DIR__, 3);
        $feature = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        RegisterFeatures::clearCache();
        $seen = [];
        foreach (RouteInventory::sampleGets(RouteInventory::routes($feature), $this->slug) as $sample) {
            $path = str_replace('\\.', '.', $sample['path']);
            if (!str_starts_with($path, '/' . $this->slug . '/') && $path !== '/' . $this->slug) {
                continue;
            }
            if ($this->skipSample($path) || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $suffix = substr($path, strlen('/' . $this->slug));
            if (str_ends_with($suffix, '.csv')) {
                $this->http->get($suffix === '' ? '/' : $suffix);
                continue;
            }
            if (str_ends_with($suffix, '.json')) {
                $this->http->get($suffix, ['{', '[']);
                continue;
            }
            $this->http->get($suffix === '' ? '' : $suffix, [], false, $suffix === '/registers' ? 180 : 90);
        }
        foreach (['/vendors', '/consignors', '/vendors/new', '/consignors/new', '/inventory/vendors.json', '/inventory/consignors.json'] as $suffix) {
            $needles = str_ends_with($suffix, '.json') ? ['{', '['] : [];
            $this->http->get($suffix, $needles);
        }
    }

    private function people(): void
    {
        $tag = $this->tag();
        $this->vendorCode = 'v' . $tag;
        $created = $this->http->post('/vendors', [
            'code' => $this->vendorCode,
            'first_name' => 'Ada',
            'last_name' => $tag,
            'email' => $tag . '@example.test',
            'payout_mode' => 'pay',
            'store_take' => '50',
            'discount_by' => 'staff',
            'expiry_action' => 'return',
        ], ['Vendor created.', 'Consignor created.']);
        $this->vendorId = $this->http->id($created->body, '#/(?:vendors|consignors)/([0-9a-fA-F-]{36})/edit#', 'vendor');
        if ($this->vendorId !== '') {
            $this->http->get('/vendors/' . $this->vendorId, [$tag]);
            $this->http->get('/vendors/' . $this->vendorId . '/edit', [$this->vendorCode]);
            $this->http->post('/vendors/' . $this->vendorId, [
                'code' => $this->vendorCode,
                'first_name' => 'Ada',
                'last_name' => $tag,
                'email' => $tag . '@example.test',
                'payout_mode' => 'pay',
                'store_take' => '50',
                'discount_by' => 'staff',
                'expiry_action' => 'return',
            ], ['Vendor saved.', 'Consignor saved.']);
            $this->http->post('/vendors/' . $this->vendorId . '/sign-in', [
                'username' => 's' . $tag,
                'display_name' => 'Ada ' . $tag,
                'password' => 'vendor-sign-in',
                'email' => $tag . '@example.test',
            ], ['Vendor sign-in added.', 'Vendor sign-in saved.']);
        }
        $other = $this->tag();
        $second = $this->http->post('/vendors', [
            'code' => 'w' . $other,
            'first_name' => 'Bea',
            'last_name' => $other,
            'payout_mode' => 'pay',
        ], ['Vendor created.', 'Consignor created.']);
        $this->otherVendorId = $this->http->id($second->body, '#/(?:vendors|consignors)/([0-9a-fA-F-]{36})/edit#', 'second vendor');
        $throwaway = $this->tag();
        $gone = $this->http->post('/vendors', [
            'code' => 'x' . $throwaway,
            'first_name' => 'Cee',
            'last_name' => $throwaway,
            'payout_mode' => 'hold',
        ], ['Vendor created.', 'Consignor created.']);
        $goneId = $this->http->id($gone->body, '#/(?:vendors|consignors)/([0-9a-fA-F-]{36})/edit#', 'vendor to remove');
        if ($goneId !== '') {
            $this->http->post('/vendors/' . $goneId . '/standing', ['standing' => 'inactive'], ['Vendor is inactive.', 'Consignor is inactive.']);
            $this->http->post('/vendors/' . $goneId . '/standing', ['standing' => 'active'], ['Vendor is active.', 'Consignor is active.']);
            $this->http->post('/vendors/' . $goneId . '/customer-role', [], ['Customer role added.']);
            $this->http->post('/vendors/' . $goneId . '/delete', [], ['Vendor deleted.', 'Consignor deleted.']);
        }
        $buyer = 'Shopper ' . $this->tag();
        $customer = $this->http->post('/customers', [
            'customer_name' => $buyer,
            'customer_email' => $this->tag() . '@example.test',
        ], ['Customer created.']);
        $this->customerId = $this->http->id($customer->body, '#/customers/([0-9a-fA-F-]{36})#', 'customer');
        if ($this->customerId !== '') {
            $this->http->get('/customers/' . $this->customerId, [$buyer]);
            $this->http->get('/customers/' . $this->customerId . '/edit', [$buyer]);
            $this->http->post('/customers/' . $this->customerId, [
                'customer_name' => $buyer,
                'customer_phone' => '555-0100',
            ], ['Customer saved.']);
            $this->http->post('/customers/' . $this->customerId . '/vendor-role', [
                'code' => 'c' . $this->tag(),
            ], ['Vendor role added.']);
        }
        $this->http->get('/parties');
        $parties = $this->http->get('/parties/rows', ['{', '[']);
        $partyId = $this->http->id($parties->body, '/"DT_RowId":"party-([0-9a-fA-F-]{36})"/', 'party');
        if ($partyId !== '') {
            $this->http->get('/parties/' . $partyId);
        }
        $wait = $this->tag();
        $listed = $this->http->post('/wait-list', [
            'first_name' => 'Ada',
            'last_name' => $wait,
            'email' => $wait . '@example.test',
            'phone' => '555-0100',
            'width_ft' => '10',
            'depth_ft' => '10',
            'notes' => 'Sweep',
        ], ['Added to the wait list.']);
        $waitId = $this->http->id($listed->body, '#/wait-list/([0-9a-fA-F-]{36})#', 'wait list');
        if ($waitId !== '') {
            $this->http->get('/wait-list/' . $waitId, [$wait]);
            $this->http->get('/wait-list/' . $waitId . '/edit', [$wait]);
            $this->http->post('/wait-list/' . $waitId, [
                'first_name' => 'Ada',
                'last_name' => $wait,
                'email' => $wait . '@example.test',
                'phone' => '555-0101',
                'status' => 'waiting',
            ], ['Wait list saved.']);
            $this->http->post('/wait-list/' . $waitId . '/delete', [], ['Removed from the wait list.']);
        }
    }

    private function catalogs(): void
    {
        $keep = 'Brand ' . $this->tag();
        $drop = 'Brand ' . $this->tag();
        $this->http->post('/brands', ['name' => $keep], ['Brand created.']);
        $this->http->post('/brands', ['name' => $drop], ['Brand created.']);
        $page = $this->http->get('/brands', [$keep]);
        $keepId = $this->safeId($page->body, 'tr', 'brand', $keep, 'brand');
        $dropId = $this->safeId($page->body, 'tr', 'brand', $drop, 'brand to merge');
        if ($keepId !== '') {
            $this->http->get('/brands/' . $keepId . '/edit');
            $this->http->post('/brands/' . $keepId, ['name' => $keep . ' saved'], ['Brand saved.']);
            $this->http->post('/brands/' . $keepId . '/active', ['active' => '0'], ['Brand is inactive.']);
            $this->http->post('/brands/' . $keepId . '/active', ['active' => '1'], ['Brand is active.']);
        }
        if ($dropId !== '' && $keepId !== '') {
            $this->http->post('/brands/' . $dropId . '/merge', ['target' => $keepId], ['Brand merged.']);
        }
        if ($keepId !== '') {
            $this->http->post('/brands/' . $keepId . '/delete', [], ['Brand deleted.']);
        }
        $name = $this->http->json('/inventory/brands', ['name' => 'Intake ' . $this->tag()], ['"id"']);
        if (preg_match('/"id":"([0-9a-fA-F-]{36})"/', $name->body, $match) === 1) {
            $this->http->get('/inventory/brands.json?q=' . rawurlencode('Intake'), ['{', '[']);
        }
        foreach (['colors' => 'color', 'categories' => 'category', 'sizes' => 'size', 'conditions' => 'condition', 'departments' => 'department'] as $path => $singular) {
            $word = ucfirst($singular);
            $left = $word . ' ' . $this->tag();
            $right = $word . ' ' . $this->tag();
            $this->http->post('/' . $path, ['name' => $left], [$word . ' created.']);
            $this->http->post('/' . $path, ['name' => $right], [$word . ' created.']);
            $list = $this->http->get('/' . $path, [$left]);
            $leftId = $this->safeId($list->body, 'tr', $singular, $left, $singular);
            $rightId = $this->safeId($list->body, 'tr', $singular, $right, $singular);
            if ($leftId !== '') {
                $this->http->get('/' . $path . '/' . $leftId . '/edit');
                $this->http->post('/' . $path . '/' . $leftId, ['name' => $left . ' saved'], [$word . ' saved.']);
                $this->http->post('/' . $path . '/' . $leftId . '/active', ['active' => '0'], [$word . ' is inactive.']);
                $this->http->post('/' . $path . '/' . $leftId . '/active', ['active' => '1'], [$word . ' is active.']);
            }
            if ($leftId !== '' && $rightId !== '') {
                $this->http->post('/' . $path . '/' . $rightId . '/merge', ['target' => $leftId], [$word . ' merged.']);
            }
            if ($leftId !== '') {
                $this->http->post('/' . $path . '/' . $leftId . '/delete', [], [$word . ' deleted.']);
            }
            $this->http->json('/inventory/' . $path, ['name' => 'Intake ' . $word . ' ' . $this->tag()], ['"id"']);
        }
    }

    private function boothsAndRent(): void
    {
        $code = 'b' . $this->tag();
        $made = $this->http->post('/booths', ['code' => $code, 'name' => $code, 'width_ft' => '10', 'depth_ft' => '10'], ['Booth created.']);
        $list = $this->http->get('/booths', [$code]);
        $this->boothId = $this->safeId($list->body, 'tr', 'booth', $code, 'booth');
        if ($made->status >= 500) {
            return;
        }
        $other = 'b' . $this->tag();
        $this->http->post('/booths', ['code' => $other, 'name' => $other], ['Booth created.']);
        $again = $this->http->get('/booths', [$other]);
        $this->otherBoothId = $this->safeId($again->body, 'tr', 'booth', $other, 'second booth');
        $spare = 'b' . $this->tag();
        $this->http->post('/booths', ['code' => $spare, 'name' => $spare], ['Booth created.']);
        $spares = $this->http->get('/booths', [$spare]);
        $spareId = $this->safeId($spares->body, 'tr', 'booth', $spare, 'spare booth');
        if ($this->boothId !== '') {
            $this->http->get('/booths/' . $this->boothId . '/edit', [$code]);
            $this->http->post('/booths/' . $this->boothId, ['code' => $code, 'name' => $code . ' saved', 'width_ft' => '10', 'depth_ft' => '10'], ['Booth saved.']);
            $this->http->post('/rates', ['booth_id' => $this->boothId, 'amount' => '100.00'], ['Asking rent saved.']);
        }
        if ($spareId !== '') {
            $this->http->post('/booths/' . $spareId . '/active', ['active' => '0'], ['Booth is inactive.']);
            $this->http->post('/booths/' . $spareId . '/active', ['active' => '1'], ['Booth is active.']);
            $this->http->post('/booths/' . $spareId . '/delete', [], ['Booth deleted.']);
        }
        if ($this->boothId === '' || $this->otherVendorId === '' || $this->otherBoothId === '') {
            return;
        }
        // History before this rent period is planted, as an import would load it;
        // the desk assigns a booth below, with its first rent.
        ProbeMall::plantStay($this->boothId, $this->otherVendorId, self::STAY_START, '', 10000);
        $vendor = $this->http->get('/vendors/' . $this->otherVendorId, [$code]);
        $assignment = $this->http->id($vendor->body, '#/assignments/([0-9a-fA-F-]{36})/#', 'assignment');
        if ($assignment === '') {
            return;
        }
        $this->http->post('/assignments/' . $assignment . '/rent', [
            'starts_on' => '2026-11-03',
            'rent' => '90.00',
        ], ['Rent rate saved.']);
        $this->http->post('/assignments/' . $assignment . '/end', ['ends_on' => self::FIRST_STAY_END], ['Booth ended.']);
        ProbeMall::plantStay($this->otherBoothId, $this->otherVendorId, self::NEXT_STAY_START, '', 9000);
        $book = $this->http->get('/rent?month=' . substr(self::RENT_ON, 0, 7), [$other]);
        $pattern = '/data-charge-stay="([0-9a-f-]{36})"\s+data-charge-period="(\d{4}-\d{2}-\d{2})"[^>]*data-charge-code="'
            . preg_quote($other, '/') . '"/';
        if (preg_match($pattern, $book->body, $button) === 1) {
            $this->http->post('/rent/charge', [
                'assignment_id' => $button[1],
                'period_start' => $button[2],
            ], ['Rent charged.']);
        } else {
            $this->http->failures[] = 'the rent page had no charge button for booth ' . $other;
        }
        $moved = $this->http->get('/vendors/' . $this->otherVendorId);
        $current = $this->http->id($moved->body, '#/assignments/([0-9a-fA-F-]{36})/#', 'moved assignment');
        if ($current !== '') {
            $this->http->post('/assignments/' . $current . '/end', ['ends_on' => self::STAY_END], ['Booth ended.']);
        }
        $this->deskAssignment();
        $this->http->get('/rent/collect?booth=' . $this->otherBoothId);
        $paid = $this->http->post('/rent/payment', [
            'booth_id' => $this->otherBoothId,
            'amount' => '10.00',
            'tender_cash' => '10.00',
            'cash_tendered' => '20.00',
        ], ['Rent receipt', 'a rent receipt needs a tender', 'They owe']);
        $receipt = '';
        if (preg_match('#/rent/receipt/([0-9a-fA-F-]{36})#', $paid->effectiveUrl, $match) === 1) {
            $receipt = strtolower($match[1]);
        }
        if ($receipt !== '') {
            $this->http->get('/rent/receipt/' . $receipt, ['Rent receipt', 'Print receipt']);
        }
        $this->http->post('/rent/waive', [], ['Choose a late fee.', 'Late fee waived.']);
        $reference = 'bx' . $this->tag();
        if ($current === '') {
            return;
        }
        $this->http->get('/booth-extras', ['Booth ' . $other . ', ']);
        $this->http->post('/booth-extras', [
            'assignment_id' => $current,
            'source_reference' => $reference,
            'amount' => '5.00',
        ], ['Booth charge posted.']);
        $this->money(true);
        $this->http->post('/booth-deposit', [
            'assignment_id' => $current,
            'source_reference' => $reference . 'd',
            'amount' => '5.00',
        ], ['Booth deposit posted.']);
        $this->http->post('/booth-deposit/release', [
            'assignment_id' => $current,
            'source_reference' => $reference . 'r',
            'amount' => '5.00',
        ], ['Booth deposit returned posted.']);
        $this->money();
    }

    /**
     * A booth assigned at the desk from the first of this rent period: see what
     * is due, then take it by card.
     */
    private function deskAssignment(): void
    {
        $desk = 'b' . $this->tag();
        $this->http->post('/booths', ['code' => $desk, 'name' => $desk, 'amount' => '25.00'], ['Booth created.']);
        $deskId = $this->safeId($this->http->get('/booths', [$desk])->body, 'tr', 'booth', $desk, 'desk booth');
        $rent = $this->http->get('/rent');
        if ($deskId === '' || preg_match('/<time datetime="(\d{4}-\d{2})-\d{2}">/', $rent->body, $month) !== 1) {
            $this->http->failures[] = 'the desk booth could not be set up';

            return;
        }
        $stay = ['booth_id' => $deskId, 'vendor_id' => $this->otherVendorId, 'starts_on' => $month[1] . '-01'];
        $this->http->post('/assignments', $stay, ['Due now: $25.00']);
        $this->http->post('/assignments', $stay + ['pay_with' => 'card'], ['Booth assigned.']);
        $later = 'b' . $this->tag();
        $this->http->post('/booths', ['code' => $later, 'name' => $later, 'amount' => '25.00'], ['Booth created.']);
        $laterId = $this->safeId($this->http->get('/booths', [$later])->body, 'tr', 'booth', $later, 'reserved booth');
        $next = (new \DateTimeImmutable($month[1] . '-01'))->modify('+2 months')->format('Y-m-d');
        $reserve = ['booth_id' => $laterId, 'vendor_id' => $this->otherVendorId, 'starts_on' => $next, 'pay_with' => 'card'];
        $reserved = $this->http->post('/assignments', $reserve, ['Booth assigned.']);
        $held = $this->http->id($reserved->body, '#/assignments/([0-9a-fA-F-]{36})/cancel#', 'reservation');
        $this->http->post('/assignments/' . $held . '/cancel', ['outcome' => 'keep', 'reason' => 'Changed their mind'], ['Reservation cancelled. The store kept the payment.']);
    }

    private function inventory(): void
    {
        $this->saleCode = $this->item($this->vendorId, '10.00', '5', '');
        $this->saleItemId = $this->itemId($this->saleCode);
        $this->voidCode = $this->item($this->vendorId, '8.00', '1', '');
        $this->voidItemId = $this->itemId($this->voidCode);
        $this->layawayCode = $this->item($this->vendorId, '12.00', '1', '');
        $this->payoutCode = $this->item($this->vendorId, '10.00', '1', '');
        $this->payoutItemId = $this->itemId($this->payoutCode);
        $this->extraCode = $this->item($this->vendorId, '4.00', '1', '');
        $this->taxCode = $this->item($this->vendorId, '10.00', '1', '');
        $this->useCode = $this->item($this->vendorId, '2.00', '1', '');
        $this->missedCode = $this->item($this->vendorId, '5.00', '1', '');
        $photo = $this->item($this->vendorId, '6.00', '1', '');
        $photoId = $this->itemId($photo);
        $remove = $this->item($this->vendorId, '3.00', '1', '');
        $removeId = $this->itemId($remove);
        $donate = $this->item($this->vendorId, '5.00', '1', '2020-06-01');
        $donateId = $this->itemId($donate);
        $buy = $this->item($this->vendorId, '5.00', '1', '2020-06-01');
        $buyId = $this->itemId($buy);
        if ($this->saleItemId !== '') {
            $this->http->get('/inventory/' . $this->saleItemId . '/edit', [$this->saleCode]);
            $this->http->post('/inventory/' . $this->saleItemId, $this->itemFields($this->vendorId, $this->saleCode, '10.00', '5', '', 'Probe ' . $this->saleCode . ' saved'), ['Item saved.']);
        }
        if ($photoId !== '') {
            $file = $this->tempFile('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '');
            $this->http->upload('/inventory/' . $photoId . '/photo', [], ['photo' => $file], ['Photo saved.']);
            $this->http->get('/inventory/' . $photoId . '/photo', ["\x89PNG"]);
        }
        if ($this->saleItemId !== '') {
            $this->http->post('/inventory/labels', [
                'stock' => 'avery_8160',
                'copies' => '1',
                'item' => $this->saleItemId,
                'start_row' => '1',
                'start_column' => '1',
            ], ['Print stickers']);
        }
        if ($removeId !== '') {
            $this->http->post('/inventory/' . $removeId . '/delete', [], ['Item deleted.']);
        }
        $this->http->get('/inventory/expired');
        if ($donateId !== '') {
            $this->http->post('/inventory/' . $donateId . '/dispose', [
                'kind' => 'donate',
                'posted_on' => self::DATE,
            ], ['Marked donated.']);
        }
        if ($buyId !== '') {
            $this->http->post('/inventory/' . $buyId . '/buy', [
                'amount' => '4.00',
                'posted_on' => self::DATE,
                'form_token' => bin2hex(random_bytes(16)),
            ], ['Bought for the store.']);
        }
        $csv = $this->tempFile('tags.csv', "code,vendor,description,price,barcode,quantity\n"
            . 'i' . $this->tag() . ',' . $this->vendorCode . ',Imported hat,7.00,,1' . "\n");
        $this->http->upload('/inventory/import', [], ['tags' => $csv], ['1 tag brought in.']);
    }

    private function registerDesk(): void
    {
        $this->money();
        $this->freshSale();
        $this->http->post('/pos/discard', [], ['Ticket discarded.', 'Start a sale, then try again.']);
        $this->sellTaxable();
        if ($this->saleCode === '' || $this->extraCode === '') {
            return;
        }
        $scanned = $this->http->post('/pos/scan', ['scan' => $this->saleCode], [$this->saleCode]);
        $line = $this->http->id($scanned->body, '/name="line_no" value="(\d+)"/', 'ticket line');
        if ($line === '') {
            $line = '1';
        }
        $this->http->post('/pos/price', ['line_no' => $line, 'price' => '11.00'], ['Price changed.']);
        $this->http->post('/pos/quantity', ['line_no' => $line, 'quantity' => '2'], ['Quantity saved.']);
        $this->http->post('/pos/quantity', ['line_no' => $line, 'quantity' => '1'], ['Quantity saved.']);
        $this->http->post('/pos/line-discount', [
            'line_no' => $line,
            'discount_action' => 'save',
            'discount_kind' => 'percent',
            'discount_value' => '10',
            'discount_reason' => 'promotion',
        ], ['Line discount saved.']);
        $this->http->post('/pos/discount', [
            'discount_action' => 'save',
            'discount_kind' => 'amount',
            'discount_value' => '1.00',
            'discount_reason' => 'damaged',
        ], ['Discount saved.']);
        $this->http->post('/pos/tax-free', [
            'waiver_action' => 'save',
            'waiver_reason' => 'resale',
        ], ['Tax updated.']);
        $this->http->post('/pos/tender', [
            'tender_role' => 'cash',
            'tender_amount' => '1.00',
        ], ['Tender saved.']);
        $this->http->post('/pos/tender/remove', ['tender_role' => 'cash'], ['Tender removed.']);
        if ($this->vendorId !== '') {
            $this->http->post('/pos/buyer', ['vendor_id' => $this->vendorId], ['Buyer saved.']);
        }
        $this->http->post('/pos/customer', [
            'customer_name' => 'Ada Shopper',
            'customer_phone' => '555-0102',
            'customer_email' => 'ada-shopper@example.test',
        ], ['Customer saved.']);
        $this->http->post('/pos/scan', ['scan' => $this->extraCode], [$this->extraCode]);
        $this->http->post('/pos/remove', ['line_no' => '2'], ['Item removed.']);
        $label = 'Sweep ' . $this->tag();
        $this->http->post('/pos/park', ['park_label' => $label], ['Ticket parked.']);
        $held = $this->http->get('/pos/suspended', [$label]);
        $ticket = $this->http->id($held->body, '/name="ticket_id" value="([0-9a-fA-F-]{36})"/', 'parked ticket');
        if ($ticket !== '') {
            $this->http->post('/pos/recall', ['ticket_id' => $ticket], ['Ticket recalled.']);
        }
        $cart = $this->http->get('/pos');
        $due = '20.00';
        if (preg_match('/data-due="([0-9]+\.[0-9]{2})"/', $cart->body, $match) === 1) {
            $due = $match[1];
        }
        $paid = $this->http->post('/pos/pay', ['tender_cash' => $due], ['Sale posted.']);
        $receipt = '';
        if (str_contains($paid->body, 'Sale posted.')) {
            $receipt = $this->locatedId($paid, '#/pos/receipt/([0-9a-fA-F-]{36})#', 'receipt');
        }
        if ($receipt !== '') {
            $this->http->get('/pos/receipt/' . $receipt, ['Receipt', $this->saleCode]);
            $this->http->post('/pos/receipt/' . $receipt . '/email', [
                'email' => ProbeMall::EMAIL,
            ], ['Receipt emailed.', 'the server mailbox is not set up', 'the receipt email could not be sent']);
            if ($this->saleItemId !== '' && $this->otherVendorId !== '') {
                $this->http->post('/pos/receipt/' . $receipt . '/booth', [
                    'item_id' => $this->saleItemId,
                    'vendor_id' => $this->otherVendorId,
                ], ['The vendor payable moved.']);
            }
            if ($this->saleItemId !== '') {
                $this->http->post('/pos/receipt/' . $receipt . '/return', [
                    'item_id' => $this->saleItemId,
                    'return_tender' => 'cash',
                ], ['Item returned.']);
            }
        }
        $this->http->post('/pos/new', [], ['name="scan"', 'Scan']);
        if ($this->voidCode !== '') {
            $this->freshSale();
        $this->http->post('/pos/discard', [], ['Ticket discarded.', 'Start a sale, then try again.']);
            $this->http->post('/pos/scan', ['scan' => $this->voidCode], [$this->voidCode]);
            $voidCart = $this->http->get('/pos');
            $voidDue = '20.00';
            if (preg_match('/data-due="([0-9]+\.[0-9]{2})"/', $voidCart->body, $match) === 1) {
                $voidDue = $match[1];
            }
            $voidedSale = $this->http->post('/pos/pay', ['tender_cash' => $voidDue], ['Sale posted.']);
            $voidReceipt = '';
            if (str_contains($voidedSale->body, 'Sale posted.')) {
                $voidReceipt = $this->locatedId($voidedSale, '#/pos/receipt/([0-9a-fA-F-]{36})#', 'void receipt');
            }
            if ($voidReceipt !== '') {
                $this->http->post('/pos/receipt/' . $voidReceipt . '/void', [], ['Ticket voided.']);
            }
        }
        if ($this->layawayCode !== '') {
            $this->freshSale();
        $this->http->post('/pos/discard', [], ['Ticket discarded.', 'Start a sale, then try again.']);
            $this->http->post('/pos/scan', ['scan' => $this->layawayCode], [$this->layawayCode]);
            $this->http->post('/pos/customer', [
                'customer_name' => 'Layaway Shopper',
                'customer_phone' => '555-0103',
                'customer_email' => 'layaway@example.test',
            ], ['Customer saved.']);
            $heldSale = $this->http->post('/pos/pay', [
                'hold' => '1',
                'tender_cash' => '1.00',
            ], ['Layaway held']);
            $layaway = '';
            if (str_contains($heldSale->body, 'Layaway held')) {
                $layaway = $this->locatedId($heldSale, '#/pos/layaway/([0-9a-fA-F-]{36})#', 'layaway');
            }
            if ($layaway !== '') {
                $this->http->get('/pos/layaway/' . $layaway, ['Layaway']);
                $this->http->get('/pos/layaways', ['Layaway']);
                $this->http->post('/pos/layaway/cancel', ['ticket_id' => $layaway], ['Layaway cancelled']);
            }
        }
        if ($this->vendorId !== '') {
            $this->freshSale();
            $this->http->post('/pos/discard', [], ['Ticket discarded.', 'Start a sale, then try again.']);
            $this->http->post('/pos/instant', [
                'vendor_id' => $this->vendorId,
                'description' => '   ',
                'price' => '9.00',
                'quantity' => '1',
            ], ['Enter a description.']);
            $this->http->post('/pos/instant', [
                'vendor_id' => $this->vendorId,
                'description' => 'Instant ' . $this->tag(),
                'price' => '9.00',
                'quantity' => '2',
            ], ['instant_added', 'Item added to cart.']);
            $this->http->post('/pos/discard', [], ['Ticket discarded.']);
        }
        $gift = $this->http->get('/pos', ['name="form_token"', 'name="scan"']);
        $token = '';
        if (preg_match('/name="form_token" value="([0-9a-f]{16,64})"/', $gift->body, $match) === 1) {
            $token = $match[1];
        }
        $sold = $this->http->post('/pos/gift', [
            'gift_amount' => '5.00',
            'gift_tender' => 'cash',
            'gift_kind' => 'gift_certificate',
            'gift_custom' => '0',
            'form_token' => $token !== '' ? $token : bin2hex(random_bytes(16)),
        ], ['Balance left']);
        $giftId = '';
        if (str_contains($sold->body, 'Balance left')) {
            $giftId = $this->locatedId($sold, '#/pos/gift/([0-9a-fA-F-]{36})#', 'gift slip');
        }
        if ($giftId !== '') {
            $this->http->get('/pos/gift/' . $giftId, ['Balance left']);
        }
        $this->http->get('/pos/suggest.json?q=' . rawurlencode($this->payoutCode), ['[', '{']);
        $this->http->get('/pos/gift-certificate.json?number=missing&kind=gift_certificate', ['"ok"']);
        $this->http->get('/pos/tender');
        if ($this->payoutCode === '') {
            return;
        }
        $this->freshSale();
        $this->http->post('/pos/discard', [], ['Ticket discarded.', 'Start a sale, then try again.']);
        $this->http->post('/pos/scan', ['scan' => $this->payoutCode], [$this->payoutCode]);
        $snapPage = $this->http->get('/pos');
        $key = '';
        if (preg_match('/posting_key&quot;:&quot;([0-9a-f-]{36})/', $snapPage->body, $match) === 1) {
            $key = $match[1];
        }
        $this->http->json('/pos/snap', [
            'posting_key' => '01900000-0000-7000-8000-000000000099',
            'item_id' => $this->payoutItemId,
            'price' => '1.00',
            'tax' => '0.00',
            'quantity' => '1',
        ], ['that snap is not on this register']);
        if ($key !== '') {
            $this->http->json('/pos/sync', ['posting_key' => $key], ['journal_no']);
        }
    }

    private function books(): void
    {
        $this->period();
        $form = $this->http->get('/adjustment', ['1000']);
        $cash = $this->account($form->body, '1000');
        $revenue = $this->account($form->body, '4000');
        if ($cash !== '' && $revenue !== '') {
            $posted = $this->http->post('/adjustment', [
                'source_reference' => 'adj' . $this->tag(),
                'posting_date' => self::DATE,
                'description' => 'Sweep adjustment',
                'line_account_1' => $cash,
                'line_side_1' => 'debit',
                'line_amount_1' => '1.00',
                'line_account_2' => $revenue,
                'line_side_2' => 'credit',
                'line_amount_2' => '1.00',
            ], ['Adjustment posted.']);
            $journal = $this->http->id($posted->body, '#/journals/([0-9a-fA-F-]{36})#', 'adjustment journal');
            if ($journal !== '') {
                $this->http->get('/journals/' . $journal, ['Sweep adjustment', 'Journal']);
                $this->http->post('/journals/' . $journal . '/void', [], ['Journal voided.']);
            }
        }
        $this->http->post('/checks/nsf', [
            'source_reference' => 'nsf' . $this->tag(),
            'amount' => '1.00',
            'posting_date' => self::DATE,
        ], ['Bounced check posted.']);
        $this->http->post('/opening', [
            'source_reference' => 'open' . $this->tag(),
            'posting_date' => self::DATE,
        ], ['an opening is available only while the book has no other journal', 'Opening posted.']);
        if ($this->vendorId !== '' && $this->missedCode !== '') {
            $token = bin2hex(random_bytes(16));
            $this->http->post('/missed-sale', [
                'vendor_id' => $this->vendorId,
                'item_code' => $this->missedCode,
                'description' => 'Missed hat',
                'memo' => 'Sweep',
                'price' => '5.00',
                'posting_date' => self::DATE,
                'post' => '1',
                'form_token' => $token,
            ], ['Missed sale posted.']);
            $this->http->post('/vendor-memo', [
                'vendor_id' => $this->vendorId,
                'direction' => 'credit',
                'amount' => '1.00',
                'reason' => 'Sweep memo',
                'posting_date' => self::DATE,
                'post' => '1',
                'form_token' => bin2hex(random_bytes(16)),
            ], ['Vendor memo posted.', 'Consignor memo posted.']);
        }
        if ($this->vendorId !== '' && $this->useCode !== '') {
            $this->http->post('/store-use', [
                'vendor_id' => $this->vendorId,
                'item_code' => $this->useCode,
                'description' => 'Store supplies',
                'memo' => 'Sweep',
                'amount' => '2.00',
                'posting_date' => self::DATE,
                'form_token' => bin2hex(random_bytes(16)),
            ], ['Store use posted.']);
        }
    }

    private function sellTaxable(): void
    {
        if ($this->taxCode === '') {
            return;
        }
        $this->freshSale();
        $this->http->post('/pos/scan', ['scan' => $this->taxCode], [$this->taxCode]);
        $cart = $this->http->get('/pos');
        $due = '10.85';
        if (preg_match('/data-due="([0-9]+\.[0-9]{2})"/', $cart->body, $match) === 1) {
            $due = $match[1];
        }
        $paid = $this->http->post('/pos/pay', ['tender_cash' => $due], ['Sale posted.']);
        if (str_contains($paid->body, 'Sale posted.')) {
            $this->http->post('/tax', [
                'source_reference' => 'tax' . $this->tag(),
                'amount' => '0.01',
                'credit' => 'bank',
                'posting_date' => self::DATE,
            ], ['Tax payment posted.']);
        }
        $this->freshSale();
    }

    private function period(): void
    {
        $book = $this->http->get('/book');
        $year = 1990;
        while ($year < 2019 && (str_contains($book->body, $year . '-01-01') || str_contains($book->body, '01-01-' . $year))) {
            $year++;
        }
        $this->http->post('/periods', [
            'starts_on' => $year . '-01-01',
            'ends_on' => $year . '-12-31',
        ], ['Journal posted.']);
        $form = $this->http->get('/adjustment');
        $cash = $this->account($form->body, '1000');
        $revenue = $this->account($form->body, '4000');
        if ($cash === '' || $revenue === '') {
            return;
        }
        $this->http->post('/adjustment', [
            'source_reference' => 'yr' . $year . $this->tag(),
            'posting_date' => $year . '-06-15',
            'description' => 'Year ' . $year,
            'line_account_1' => $cash,
            'line_side_1' => 'debit',
            'line_amount_1' => '1.00',
            'line_account_2' => $revenue,
            'line_side_2' => 'credit',
            'line_amount_2' => '1.00',
        ], ['Adjustment posted.']);
        $close = $this->http->get('/close', ['Close the year']);
        $openPeriod = '/<tr>\s*<td>(\d{4}-\d{2}-\d{2}|\d{2}-\d{2}-\d{4})<\/td>\s*<td>(\d{4}-\d{2}-\d{2}|\d{2}-\d{2}-\d{4})<\/td>\s*<td>open<\/td>.*?name="period_id" value="([0-9a-fA-F-]{36})"/s';
        if (preg_match_all($openPeriod, $close->body, $found, PREG_SET_ORDER) < 1) {
            $this->http->failures[] = 'no open period was listed for ' . $year;

            return;
        }
        foreach ($found as $period) {
            $start = $this->iso($period[1]);
            if ($start !== $year . '-01-01') {
                continue;
            }
            $this->http->post('/close', [
                'period_id' => $period[3],
                'ends_on' => $this->iso($period[2]),
            ], ['Period closed.']);

            return;
        }
        $this->http->failures[] = 'the ' . $year . ' period was not open to close';
    }

    private function storefront(): void
    {
        $this->money();
        $this->http->post('/settings', [
            'settings_part' => 'money',
            'default_store_take' => '10',
            'tax_rate' => '8.5',
            'operating_mode' => 'both',
        ], ['Save Money, Building, or Receipt from that Settings page.']);
        $receipt = $this->http->get('/settings/receipt');
        $footer = '';
        if (preg_match('/name="receipt_footer"[^>]*>(.*?)<\/textarea>/s', $receipt->body, $match) === 1) {
            $footer = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        }
        $via = 'server';
        if (preg_match('/<option value="(server|gmail)" selected>/', $receipt->body, $match) === 1) {
            $via = $match[1];
        }
        $gmail = $this->input($receipt->body, 'gmail_address');
        $this->http->post('/settings/receipt', [
            'settings_part' => 'receipt',
            'receipt_footer' => $footer,
            'receipt_mail_via' => $via,
            'gmail_address' => $gmail,
        ], ['Receipt saved.']);
        $company = $this->http->get('/company');
        $this->http->post('/company', [
            'store_name' => $this->input($company->body, 'store_name') !== '' ? $this->input($company->body, 'store_name') : ProbeMall::DISPLAY_NAME,
            'street' => $this->input($company->body, 'street'),
            'street2' => $this->input($company->body, 'street2'),
            'city' => $this->input($company->body, 'city'),
            'state' => $this->input($company->body, 'state'),
            'postal' => $this->input($company->body, 'postal'),
            'phone' => $this->input($company->body, 'phone'),
            'email' => $this->input($company->body, 'email'),
        ], ['Company saved.']);
        $span = $this->saleSpan();
        $this->http->post('/mall-sale', [
            'name' => 'Sweep ' . $this->tag(),
            'percent' => '10',
            'absorbed_by' => 'mall',
            'starts_on' => $span['start'],
            'ends_on' => $span['end'],
        ], ['Mall sale saved.']);
        if ($this->saleCode !== '') {
            $this->http->post('/floor', [
                'code' => $this->saleCode,
                'kind' => 'missing',
                'noted_on' => self::DATE,
                'noted_by' => 'Sweep',
            ], ['Floor note saved.']);
            $this->http->post('/floor', [
                'code' => 'missing-' . $this->tag(),
                'kind' => 'misplaced',
                'noted_on' => self::DATE,
                'noted_by' => 'Sweep',
            ], ['That tag is not on the floor.']);
            $this->http->post('/pickups', [
                'code' => $this->saleCode,
                'picked_up_on' => self::DATE,
                'received_by' => 'Sweep',
            ], ['Pickup saved.']);
        }
        $this->map();
        if ($this->vendorId !== '') {
            $letter = $this->http->post('/letters', [
                'subject' => 'Sweep ' . $this->tag(),
                'message' => 'Hello from the sweep.',
                'vendor_id' => $this->vendorId,
            ], ['The letter is filed.']);
            $letterId = $this->http->id($letter->effectiveUrl, '#/letters/([0-9a-fA-F-]{36})#', 'letter');
            if ($letterId !== '') {
                $this->http->get('/letters/' . $letterId, ['Sweep']);
            }
        }
        $this->http->post('/export', [
            'system' => 'quickbooks',
            'from' => '2026-01-01',
            'to' => self::DATE,
            'tax_rate' => 'Tax Exempt',
        ]);
        $this->http->post('/backup', ['return' => 'settings/backup'], ['PK', 'The backup could not be taken.'], 180);
        $reportName = 'Sweep ' . $this->tag();
        $this->http->post('/reports/build', [
            'name' => $reportName,
            'sheet' => 'sales',
            'grouped' => 'day',
            'range_key' => 'this_month',
        ], ['Report saved.']);
        $saved = $this->http->get('/reports/build', [$reportName]);
        $reportId = $this->linkedId($saved->body, 'reports/saved', $reportName);
        if ($reportId !== '') {
            $this->http->get('/reports/saved/' . $reportId, ['Sales']);
            $this->http->post('/reports/saved/' . $reportId . '/delete', [], ['Report removed.']);
        }
        $deal = 'Deal ' . $this->tag();
        $this->http->post('/offers', [
            'name' => $deal,
            'scope' => 'store',
            'kind' => 'bundle',
            'threshold' => '2',
            'price' => '5.00',
            'funded_by' => 'store',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ], ['Deal saved.']);
        $offers = $this->http->get('/offers', [$deal]);
        $offerId = $this->linkedId($offers->body, 'offers', $deal);
        if ($offerId !== '') {
            $this->http->get('/offers/' . $offerId, [$deal]);
            $this->http->post('/offers/' . $offerId, [
                'name' => $deal . ' saved',
                'scope' => 'store',
                'kind' => 'bundle',
                'threshold' => '2',
                'price' => '4.00',
                'funded_by' => 'store',
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-12-31',
            ], ['Deal saved.']);
            $this->http->post('/offers/' . $offerId . '/end', [], ['Deal ended.']);
        }
    }

    private function map(): void
    {
        $editor = $this->http->get('/map/edit');
        $existing = $this->floorIds($editor->body);
        $placed = $this->placements($existing);
        $name = 'Sweep ' . $this->tag();
        $added = $this->http->post('/map/floors', ['name' => $name], ['Floor added.']);
        $floorId = $this->addedFloorId($added);
        if ($floorId === '') {
            $this->http->failures[] = 'the new floor was not on the map editor';

            return;
        }
        $renamed = $name . ' b';
        $this->http->post('/map/floors/' . $floorId, ['name' => $renamed], ['Floor renamed.']);
        if ($existing !== [] && !in_array($floorId, $existing, true)) {
            $this->http->post('/map/floors/' . $floorId . '/delete', [], ['Floor removed.']);
        }
        $live = $this->http->get('/map/edit');
        $floors = $this->floorIds($live->body);
        $home = $floors[0] ?? ($existing === [] ? $floorId : '');
        if ($home === '' || $this->boothId === '') {
            if ($home === '') {
                $this->http->failures[] = 'no floor was left to place a booth';
            }

            return;
        }
        $allowed = array_fill_keys($floors === [] ? [$home] : $floors, true);
        $placed = array_values(array_filter(
            $placed,
            static fn (array $row): bool => isset($allowed[$row['floorId']]),
        ));
        foreach ($placed as $row) {
            if ($row['id'] === $this->boothId) {
                $saved = $this->http->post('/map/edit', [
                    'floor' => $home,
                    'layout' => json_encode($placed, JSON_THROW_ON_ERROR),
                ], ['Map saved.']);
                if (!str_contains($saved->body, 'Map saved.')) {
                    $this->http->failures[] = 'map layout had ' . count($placed) . ' booths on ' . $home;
                }

                return;
            }
        }
        $spot = $this->freeSpot($placed, $home);
        $placed[] = [
            'id' => $this->boothId,
            'floorId' => $home,
            'x' => $spot['x'],
            'z' => $spot['z'],
        ];
        $layout = json_encode($placed, JSON_THROW_ON_ERROR);
        $saved = $this->http->post('/map/edit', [
            'floor' => $home,
            'layout' => $layout,
        ], ['Map saved.']);
        if (!str_contains($saved->body, 'Map saved.')) {
            $this->http->failures[] = 'map layout had ' . count($placed) . ' booths on ' . $home;
        }
    }

    private function registers(): void
    {
        $this->freshSale();
        $this->http->post('/pos/discard', [], ['Ticket discarded.', 'Start a sale, then try again.']);
        if ($this->registerId !== '') {
            $this->http->post('/registers/' . $this->registerId . '/drop', [
                'amount' => '1.00',
                'posting_date' => $this->today,
            ], ['Cash dropped.']);
            $this->http->post('/registers/' . $this->registerId . '/close', [
                'counted_cash' => '0.00',
                'counted_card' => '0.00',
                'counted_checks' => '0.00',
                'confirm_variance' => 'yes',
                ...$this->zeroCounts(),
            ], ['Register closed.']);
            $this->http->post('/registers/' . $this->registerId . '/open', [
                'cashier' => ProbeMall::USERNAME,
                'opening_float' => '0.00',
                ...$this->zeroCounts(),
            ], ['Register opened.', 'Close register']);
            $this->http->post('/registers/' . $this->registerId . '/use', [], ['is the active register.']);
            $shifts = $this->http->get('/registers/' . $this->registerId . '/shifts');
            $session = $this->http->id($shifts->body, '#/registers/sessions/([0-9a-fA-F-]{36})#', 'register session');
            if ($session !== '') {
                $this->http->get('/registers/sessions/' . $session);
            }
        }
        $name = 'Sweep ' . $this->tag();
        $this->http->post('/registers', ['name' => $name], ['Register added.']);
        $list = $this->http->get('/registers', [$name], false, 180);
        $spare = $this->http->id($list->body, '#/registers/([0-9a-fA-F-]{36})" data-brand-name="' . preg_quote($name, '#') . '#', 'spare register');
        if ($spare !== '') {
            $this->http->get('/registers/' . $spare . '/edit', [$name]);
            $this->http->post('/registers/' . $spare, ['name' => $name . ' b'], ['Register renamed.']);
            $this->http->post('/registers/' . $spare . '/delete', [], ['Register deleted.']);
        }
        $this->http->post('/registers/close-cancel', [], ['Register', 'Shift']);
    }

    private function staff(): void
    {
        $cashier = $this->addStaff('cashier', 'Cashier added.');
        $manager = $this->addStaff('manager', 'Manager added.');
        $books = $this->addStaff('books', 'Books login added.');
        $owner = $this->addStaff('owner', 'Owner added.');
        if ($cashier !== '') {
            $this->http->post('/staff/' . $cashier . '/password', [
                'email' => 'cashier-' . $cashier . '@example.test',
                'password' => 'cashier-new',
            ], ['Cashier password saved.']);
            $this->http->post('/staff/' . $cashier . '/suspend', [], ['Person suspended.']);
            $this->http->post('/staff/' . $cashier . '/restore', [], ['Person restored.']);
            $this->http->post('/staff/' . $cashier . '/role', ['role' => 'manager'], ['Role saved.']);
        }
        if ($manager !== '') {
            $this->http->post('/staff/' . $manager . '/manager-password', [
                'email' => 'manager-' . $manager . '@example.test',
                'password' => 'manager-new',
            ], ['Manager password saved.']);
        }
        if ($books !== '') {
            $this->http->post('/staff/' . $books . '/books-password', [
                'email' => 'books-' . $books . '@example.test',
                'password' => 'books-new',
            ], ['Books password saved.']);
        }
        if ($owner !== '') {
            $this->http->post('/staff/owners/' . $owner . '/password', [
                'email' => 'owner-' . $owner . '@example.test',
                'password' => 'owner-new-pass',
            ], ['Owner sign-in saved.']);
            $this->http->post('/staff/owners/' . $owner . '/suspend', [], ['Owner suspended.']);
            $this->http->post('/staff/owners/' . $owner . '/restore', [], ['Owner restored.']);
            $this->http->post('/staff/owners/' . $owner . '/remove', [], ['Owner removed.']);
        }
    }

    private function payouts(): void
    {
        if ($this->vendorId === '') {
            return;
        }
        $before = $this->http->get('/payouts/past');
        $known = [];
        if (preg_match_all('/name="run_id" value="([0-9a-fA-F-]{36})"/', $before->body, $matches) > 0) {
            foreach ($matches[1] as $id) {
                $known[strtolower($id)] = true;
            }
        }
        $paid = $this->http->post('/payout', ['vendor_id' => $this->vendorId], ['Payout posted.', 'a payout clears a positive open payable or open rent receivable']);
        if (str_contains($paid->body, 'Payout posted.')) {
            $screen = $this->http->get('/payout?vendor=' . $this->vendorId);
            $run = '';
            if (preg_match('/name="run_id" value="([0-9a-fA-F-]{36})"/', $screen->body, $match) === 1) {
                $run = strtolower($match[1]);
            }
            if ($run !== '') {
                $this->http->get('/payouts/checks?run=' . $run, ['check', 'That payout is missing.', 'not ready']);
                $this->http->post('/payouts/checks?run=' . $run, [], ['check', 'That payout is missing.', 'not ready']);
                $this->http->get('/vendor/checks/' . $run . '?as=' . rawurlencode($this->vendorId));
                $this->http->post('/payout/undo', [
                    'vendor_id' => $this->vendorId,
                    'run_id' => $run,
                    'reason' => 'Sweep undo',
                ], ['Payout undone.']);
            }
        }
        $closed = $this->http->post('/payouts/close', ['tender' => 'check'], ['is paid.', 'already paid.'], 180);
        $past = $this->http->get('/payouts/past');
        $batch = '';
        if (preg_match_all('/name="run_id" value="([0-9a-fA-F-]{36})"/', $past->body, $matches) > 0) {
            foreach ($matches[1] as $id) {
                if (!isset($known[strtolower($id)])) {
                    $batch = strtolower($id);
                    break;
                }
            }
        }
        if ($batch !== '' && str_contains($closed->body, 'is paid.')) {
            $this->http->post('/payouts/undo', [
                'run_id' => $batch,
                'reason' => 'Sweep batch undo',
            ], ['Payout undone.']);

            return;
        }
        $this->http->post('/payouts/undo', ['run_id' => '', 'reason' => ''], ['a payout reversal needs the operator and a reason']);
    }

    private function vendorPortal(): void
    {
        $this->building();
        if ($this->vendorId === '') {
            return;
        }
        $as = $this->vendorId;
        foreach (['/vendor', '/vendor/sales', '/vendor/inventory', '/vendor/offers', '/vendor/offers/new', '/vendor/inventory/new', '/vendor/labels'] as $suffix) {
            $this->http->get(VendorPreview::with($suffix, $as));
        }
        $this->http->get(VendorPreview::with('/vendor/inventory/rows?q=' . rawurlencode($this->vendorCode), $as), ['{', '[']);
        $this->http->get(VendorPreview::with('/vendor/offers/items.json?q=' . rawurlencode($this->vendorCode), $as), ['[', '{']);
        $created = $this->http->post(VendorPreview::with('/vendor/inventory/new', $as), [
            'description' => 'Portal ' . $this->tag(),
            'price' => '6.00',
            'quantity' => '1',
            'placement' => 'consigned',
            'intake_on' => '2026-06-01',
        ], ['Item created.']);
        $item = $this->http->id($created->effectiveUrl, '/print=([0-9a-fA-F-]{36})/', 'portal item');
        if ($item === '') {
            $rows = $this->http->get(VendorPreview::with('/vendor/inventory/rows', $as), ['{', '[']);
            $item = $this->http->id($rows->body, '/"DT_RowId":"item-([0-9a-fA-F-]{36})"/', 'portal item row');
        }
        if ($item !== '') {
            $this->http->get(VendorPreview::with('/vendor/inventory/' . $item . '/edit', $as));
            $this->http->post(VendorPreview::with('/vendor/inventory/' . $item, $as), [
                'description' => 'Portal saved ' . $this->tag(),
                'price' => '6.50',
                'quantity' => '1',
                'placement' => 'consigned',
                'intake_on' => '2026-06-01',
            ], ['Item saved.']);
            $this->http->post(VendorPreview::with('/vendor/labels', $as), [
                'stock' => 'avery_8160',
                'copies' => '1',
                'item' => $item,
                'start_row' => '1',
                'start_column' => '1',
            ], ['Print stickers']);
            $this->http->post(VendorPreview::with('/vendor/inventory/' . $item . '/delete', $as), [], ['Item deleted.']);
        }
        $deal = 'Portal deal ' . $this->tag();
        $this->http->post(VendorPreview::with('/vendor/offers', $as), [
            'name' => $deal,
            'scope' => 'vendor',
            'kind' => 'bundle',
            'threshold' => '2',
            'price' => '3.00',
            'funded_by' => 'vendor',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ], ['Deal saved.']);
        $list = $this->http->get(VendorPreview::with('/vendor/offers', $as), [$deal]);
        $offer = $this->linkedId($list->body, 'vendor/offers', $deal);
        if ($offer !== '') {
            $this->http->get(VendorPreview::with('/vendor/offers/' . $offer, $as), [$deal]);
            $this->http->post(VendorPreview::with('/vendor/offers/' . $offer, $as), [
                'name' => $deal . ' saved',
                'scope' => 'vendor',
                'kind' => 'bundle',
                'threshold' => '2',
                'price' => '2.00',
                'funded_by' => 'vendor',
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-12-31',
            ], ['Deal saved.']);
            $this->http->post(VendorPreview::with('/vendor/offers/' . $offer . '/end', $as), [], ['Deal ended.']);
        }
    }

    private function doors(): void
    {
        $this->http->get('/login', ["Today's sales"]);
        $this->http->post('/login', [
            'email' => ProbeMall::EMAIL,
            'password' => ProbeMall::PASSWORD,
        ], ["Today's sales"]);
        $this->http->get('/forgot', ['name="email"']);
        $this->http->post('/forgot', ['email' => ProbeMall::EMAIL], ['name="email"', ResetNotice::SENT]);
        $token = str_repeat('A', 43);
        $this->http->get('/reset/' . $token, [ResetNotice::EXPIRED]);
        $this->http->post('/reset/' . $token, [
            'password' => 'another-password',
            'confirm' => 'another-password',
        ], [ResetNotice::EXPIRED]);
        $this->http->get('/consignor/enter', ['consignor page', 'email']);
        $this->http->post('/consignor/enter', ['email' => $this->tag() . '@example.test'], [VendorEntry::SENT]);
        $consignor = str_repeat('a', 64);
        $this->http->get('/consignor/enter/' . $consignor, ['Open your account']);
        $this->http->post('/consignor/enter/' . $consignor, [], ['That link has expired.']);
        $this->http->get('/consignor', ['consignor page', 'Your account']);
        $this->http->post('/consignor/preference', ['payout_mode' => 'hold'], ['consignor page', 'Payout preference saved.']);
        $this->http->post('/consignor/logout', [], ['Signed out.', 'consignor page']);
        $this->http->post('/reset-test-data', ['confirm' => 'no'], ['Type RESET to confirm.', 'This address is not a page in'], 90, true);
    }

    private function password(): void
    {
        $page = $this->http->get('/password', ['Current password', 'password']);
        $device = $this->http->id($page->body, '#name="device_id" value="([0-9a-f-]{36})"#', 'trusted browser');
        if ($device !== '') {
            $this->http->post('/password/devices/remove', ['device_id' => $device], ['That browser was removed.']);
        }
        $this->http->post('/password', [
            'current_password' => 'not-the-password',
            'password' => 'another-password',
            'confirm' => 'another-password',
        ], ['That password is wrong.']);
        $this->http->post('/password/email', [
            'email' => 'nobody-' . $this->tag() . '@example.test',
            'current_password' => 'not-the-password',
        ], ['That password is wrong.']);
        $temporary = 'sweep-temp-pass';
        $changed = $this->http->post('/password', [
            'current_password' => ProbeMall::PASSWORD,
            'password' => $temporary,
            'confirm' => $temporary,
        ], ['Password updated.']);
        if (!str_contains($changed->body, 'Password updated.')) {
            return;
        }
        $this->client->signIn($this->slug, ProbeMall::USERNAME, $temporary);
        $restored = $this->http->post('/password', [
            'current_password' => $temporary,
            'password' => ProbeMall::PASSWORD,
            'confirm' => ProbeMall::PASSWORD,
        ], ['Password updated.']);
        $this->client->signIn($this->slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        if (!str_contains($restored->body, 'Password updated.')) {
            $this->http->failures[] = 'the probe owner password was not restored';
        }
    }

    private function signOut(): void
    {
        $this->http->post('/logout', [], ['Sign out']);
        $this->http->post('/logout/confirm', [], ['Signed out.']);
    }

    /**
     * Settings → Item counts, a lower count with its reason, and a vendor who
     * leaves: Close out, Make inactive, the stray held and picked up, and the
     * store keeping what was left after the waiting days.
     */
    private function countsAndCloseOut(): void
    {
        $this->http->get('/settings/counts', ['Why are there fewer?']);
        $label = 'Sweep reason ' . $this->tag();
        $added = $this->http->post('/settings/counts/reasons', ['label' => $label, 'kind' => 'leaving', 'who' => 'staff'], ['Reason added.']);
        $reason = $this->http->id($added->body, '#value="' . preg_quote(htmlspecialchars($label), '#') . '"(?:(?!</tr>).)*/settings/counts/reasons/([0-9a-fA-F-]{36})"#s', 'new reason');
        $this->http->post('/settings/counts/reasons/' . $reason, ['label' => $label . ' saved', 'kind' => 'correction', 'who' => 'both'], ['Reason saved.']);
        $this->http->post('/settings/counts/reasons/' . $reason . '/active', ['active' => '0'], ['Reason switched off.']);
        $this->http->post('/settings/counts/reasons/' . $reason . '/delete', [], ['Reason deleted.']);
        $this->http->post('/settings/counts/wait', ['abandon_wait_days' => '1'], ['Saved.']);

        $tag = $this->tag();
        $created = $this->http->post('/vendors', ['code' => 'c' . $tag, 'first_name' => 'Cleo', 'last_name' => $tag, 'store_take' => '50'], ['Vendor created.', 'Consignor created.']);
        $leaver = $this->http->id($created->body, '#/(?:vendors|consignors)/([0-9a-fA-F-]{36})/edit#', 'leaving vendor');
        $booth = 'k' . $tag;
        $this->http->post('/booths', ['code' => $booth, 'name' => $booth, 'amount' => '25.00'], ['Booth created.']);
        $boothId = $this->safeId($this->http->get('/booths', [$booth])->body, 'tr', 'booth', $booth, 'close-out booth');
        if ($leaver === '' || $boothId === '') {
            return;
        }
        ProbeMall::plantStay($boothId, $leaver, '2026-01-01', '2026-02-28', 2500);
        $vaseCode = $this->item($leaver, '9.00', '3', '');
        $vase = $this->itemId($vaseCode);
        $lampCode = $this->item($leaver, '7.00', '1', '');
        $lamp = $this->itemId($lampCode);
        $keepCode = $this->item($leaver, '5.00', '1', '');
        $keep = $this->itemId($keepCode);
        $lower = $this->itemFields($leaver, $vaseCode, '9.00', '2', '', 'Probe ' . $vaseCode);
        $asked = $this->http->post('/inventory/' . $vase, $lower, ['Why are there fewer?']);
        $damaged = $this->http->id($asked->body, '#name="quantity_reason" value="([0-9a-f-]{36})">Damaged<#', 'Damaged reason');
        $this->http->post('/inventory/' . $vase, $lower + ['quantity_reason' => $damaged], ['Item saved.', 'Count history']);

        $base = '/vendors/' . $leaver . '/close-out';
        $page = $this->http->get($base, ['Close out Cleo', 'Mark all as Took it home']);
        $lost = $this->http->id($page->body, '#name="item_id" value="' . preg_quote($lamp, '#') . '">(?:(?!</form>).)*?name="quantity_reason" value="([0-9a-f-]{36})">Lost or stolen<#s', 'Lost or stolen reason');
        $this->http->post($base . '/count', ['item_id' => $lamp, 'quantity_reason' => $lost], ['Item counted out.']);
        $this->http->post($base . '/keep', ['item_id' => $keep], ['The store keeps that piece. It is now store stock.']);
        $this->http->post($base . '/all', [], ['Every item is marked Took it home.']);
        $this->http->post('/vendors/' . $leaver . '/standing', ['standing' => 'inactive'], ['Vendor is inactive.', 'Consignor is inactive.']);
        $this->http->post('/inventory/' . $vase . '/hold', [], ['Held for Cleo']);
        $this->http->post($base . '/picked-up', ['item_id' => $vase], ['Pickup saved.']);
        $this->http->post('/settings/counts/wait', ['abandon_wait_days' => ''], ['Saved.']);
    }

    /**
     * Settings → Bad debts, then a vendor who left owing July: write it off,
     * take some back, and the Bad debts report and its download.
     */
    private function badDebts(): void
    {
        $this->http->get('/settings/bad-debts', ['Days past due before a debt can be written off']);
        $this->http->post('/settings/bad-debts', ['write_off_wait_days' => '90', 'unclaimed_dormancy_days' => '1095'], ['Saved.']);
        $tag = $this->tag();
        $created = $this->http->post('/vendors', ['code' => 'd' . $tag, 'first_name' => 'Dora', 'last_name' => $tag, 'store_take' => '50'], ['Vendor created.', 'Consignor created.']);
        $debtor = $this->http->id($created->body, '#/(?:vendors|consignors)/([0-9a-fA-F-]{36})/edit#', 'debtor');
        $booth = 'd' . $tag;
        $this->http->post('/booths', ['code' => $booth, 'name' => $booth, 'amount' => '30.00'], ['Booth created.']);
        $boothId = $this->safeId($this->http->get('/booths', [$booth])->body, 'tr', 'booth', $booth, 'bad debt booth');
        if ($debtor === '' || $boothId === '') {
            return;
        }
        ProbeMall::plantStay($boothId, $debtor, '2026-06-01', '2026-07-31', 3000);
        $july = $this->http->get('/rent?month=2026-07', [$booth]);
        $pattern = '/data-charge-stay="([0-9a-f-]{36})"\s+data-charge-period="(\d{4}-\d{2}-\d{2})"[^>]*data-charge-code="'
            . preg_quote($booth, '/') . '"/';
        if (preg_match($pattern, $july->body, $button) !== 1) {
            $this->http->failures[] = 'the rent page had no charge button for booth ' . $booth;

            return;
        }
        $this->http->post('/rent/charge', ['assignment_id' => $button[1], 'period_start' => $button[2]], ['Rent charged.']);
        $this->http->get('/vendors/' . $debtor . '/write-off', ['Writing it off records it as a loss.']);
        $written = $this->http->post('/vendors/' . $debtor . '/write-off', ['reason' => 'Moved away'], ['$30.00 written off.']);
        $journal = $this->http->id($written->body, '#/write-offs/([0-9a-f-]{36})/recover#', 'write-off');
        $this->http->post('/vendors/' . $debtor . '/write-offs/' . $journal . '/recover', ['amount' => '10.00'], ['$10.00 taken back.']);
        $this->http->get('/reports/bad-debts', ['Moved away']);
        $this->http->get('/reports/bad-debts.csv', ['Moved away']);
        $this->unclaimed();
    }

    /**
     * Money the store cannot pay a vendor: with no waiting days, the sale's
     * money moves to unclaimed, is paid out in part, and the rest is sent.
     */
    private function unclaimed(): void
    {
        $tag = $this->tag();
        $created = $this->http->post('/vendors', ['code' => 'q' . $tag, 'first_name' => 'Quin', 'last_name' => $tag, 'store_take' => '0'], ['Vendor created.', 'Consignor created.']);
        $quin = $this->http->id($created->body, '#/(?:vendors|consignors)/([0-9a-fA-F-]{36})/edit#', 'unclaimed vendor');
        $code = $this->item($quin, '40.00', '1', '');
        if ($quin === '' || $code === '') {
            return;
        }
        $this->http->post('/pos/new', []);
        $this->http->post('/pos/discard', []);
        $cart = $this->http->post('/pos/scan', ['scan' => $code], [$code]);
        if (preg_match('/class="register-due">\$([0-9.]+)</', $cart->body, $due) !== 1) {
            $this->http->failures[] = 'the unclaimed sale shows no amount due';

            return;
        }
        $this->http->post('/pos/pay', ['tender_cash' => $due[1], 'cash_received' => $due[1]], ['Sale posted.']);
        $this->http->post('/pos/new', []);
        $this->http->post('/settings/bad-debts', ['write_off_wait_days' => '90', 'unclaimed_dormancy_days' => '0'], ['Saved.']);
        $this->http->post('/vendors/' . $quin . '/unclaimed', ['reason' => 'No reply'], ['Moved to unclaimed money.']);
        $this->http->post('/vendors/' . $quin . '/unclaimed/pay', ['amount' => '15.00', 'pay_with' => 'check', 'check_number' => 'S' . $tag], ['Paid from unclaimed money.']);
        $this->http->post('/vendors/' . $quin . '/unclaimed/remit', ['amount' => '25.00', 'state_reference' => 'ST-' . $tag], ['Recorded as sent to the state.']);
        $this->http->post('/settings/bad-debts', ['write_off_wait_days' => '90', 'unclaimed_dormancy_days' => '1095'], ['Saved.']);
        $this->http->get('/reports/unclaimed', ['ST-' . $tag]);
        $this->http->get('/reports/unclaimed.csv', ['ST-' . $tag]);
    }

    private function item(string $vendorId, string $price, string $quantity, string $expires): string
    {
        if ($vendorId === '') {
            return '';
        }
        $code = 't' . $this->tag();
        $this->http->post('/inventory', $this->itemFields($vendorId, $code, $price, $quantity, $expires, 'Probe ' . $code), ['Item created.']);

        return $code;
    }

    /**
     * @return array<string, string>
     */
    private function itemFields(string $vendorId, string $code, string $price, string $quantity, string $expires, string $description): array
    {
        $intake = '2026-06-01';
        if ($expires !== '' && $expires < $intake) {
            $intake = '2020-01-01';
        }

        return [
            'code' => $code,
            'description' => $description,
            'vendor_id' => $vendorId,
            'price' => $price,
            'quantity' => $quantity,
            'barcode' => 'BC-' . $code,
            'intake_on' => $intake,
            'placement' => 'consigned',
            'expires_on' => $expires,
        ];
    }

    private function itemId(string $code): string
    {
        if ($code === '') {
            return '';
        }
        $rows = $this->http->get('/inventory/rows?q=' . rawurlencode($code), ['{', '[']);

        return $this->http->id($rows->body, '/"DT_RowId":"item-([0-9a-fA-F-]{36})"/', 'item ' . $code);
    }

    private function addStaff(string $role, string $sentence): string
    {
        $username = $role[0] . $this->tag();
        $email = $username . '@example.test';
        $this->http->post('/staff', [
            'role' => $role,
            'username' => $username,
            'display_name' => ucfirst($role) . ' ' . $username,
            'password' => 'long-enough',
            'email' => $email,
        ], [$sentence]);
        $page = $this->http->get('/staff', [$username]);
        if ($role === 'owner') {
            $pos = strpos($page->body, $username . ' ·');
            if ($pos === false) {
                $this->http->failures[] = $username . ' was not on the staff page';

                return '';
            }

            return $this->http->id(substr($page->body, $pos, 2000), '#/staff/owners/([0-9a-fA-F-]{36})/password#', 'owner ' . $username);
        }
        $action = match ($role) {
            'manager' => 'manager-password',
            'books' => 'books-password',
            default => 'password',
        };
        $pos = strpos($page->body, '(' . $username . ')');
        if ($pos === false) {
            $this->http->failures[] = $username . ' was not on the staff page';

            return '';
        }
        $slice = substr($page->body, $pos, 1200);

        return $this->http->id($slice, '#/staff/([0-9a-fA-F-]{36})/' . $action . '#', $role . ' ' . $username);
    }

    /**
     * @param list<string> $floors
     * @return list<array{id: string, floorId: string, x: int, z: int, width?: int, depth?: int}>
     */
    private function placements(array $floors): array
    {
        $placed = [];
        $seen = [];
        $floors = $floors === [] ? [''] : $floors;
        foreach ($floors as $floor) {
            $suffix = $floor === '' ? '/map/edit' : '/map/edit?floor=' . $floor;
            $page = $floor === '' ? null : $this->http->get($suffix);
            $html = $page === null ? '' : $page->body;
            if ($floor === '') {
                continue;
            }
            $current = $floor;
            if (preg_match('/data-floor="([0-9a-fA-F-]{36})"/', $html, $match) === 1) {
                $current = strtolower($match[1]);
            }
            if (preg_match('/<pre id="map-booths" hidden>(.*?)<\/pre>/s', $html, $block) !== 1) {
                continue;
            }
            $decoded = json_decode(html_entity_decode($block[1], ENT_QUOTES | ENT_HTML5), true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $booth) {
                if (!is_array($booth) || !is_string($booth['id'] ?? null)) {
                    continue;
                }
                if (!is_int($booth['x'] ?? null) && !is_numeric($booth['x'] ?? null)) {
                    continue;
                }
                if ($booth['x'] === null || $booth['z'] === null) {
                    continue;
                }
                $ownFloor = $booth['floorId'] ?? null;
                $floorId = is_string($ownFloor) && preg_match('/^[0-9a-fA-F-]{36}$/', $ownFloor) === 1
                    ? strtolower($ownFloor)
                    : $current;
                $x = (int) $booth['x'];
                $z = (int) $booth['z'];
                if ($x < 0 || $z < 0) {
                    continue;
                }
                $row = [
                    'id' => strtolower($booth['id']),
                    'floorId' => $floorId,
                    'x' => $x,
                    'z' => $z,
                ];
                $width = $booth['width'] ?? null;
                $depth = $booth['depth'] ?? null;
                if (is_numeric($width) && is_numeric($depth) && (int) $width > 0 && (int) $depth > 0) {
                    $row['width'] = (int) $width;
                    $row['depth'] = (int) $depth;
                }
                if (isset($seen[$row['id']])) {
                    continue;
                }
                $seen[$row['id']] = true;
                $placed[] = $row;
            }
        }

        return $placed;
    }

    private function addedFloorId(PageResponse $added): string
    {
        if (preg_match('/[?&]floor=([0-9a-fA-F-]{36})/', $added->effectiveUrl, $match) === 1) {
            return strtolower($match[1]);
        }
        if (preg_match('/id="map-floor-field" value="([0-9a-fA-F-]{36})"/', $added->body, $match) === 1) {
            return strtolower($match[1]);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function floorIds(string $html): array
    {
        $ids = [];
        if (preg_match_all('/data-floor="([0-9a-fA-F-]{36})"/', $html, $matches) > 0) {
            foreach ($matches[1] as $id) {
                $ids[strtolower($id)] = strtolower($id);
            }
        }

        return array_values($ids);
    }

    private function money(bool $deposits = false): void
    {
        $fields = [
            'settings_part' => 'money',
            'default_store_take' => '10',
            'tax_rate' => '8.5',
            'cash_round_minor' => '0',
            'operating_mode' => 'both',
            'shop_flags' => '1',
            'store_stock_enabled' => '1',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '1',
            'late_fee_grace_days' => '0',
        ];
        if ($deposits) {
            $fields['booth_deposits_enabled'] = '1';
        }
        $this->http->post('/settings/money', $fields, ['Money saved.']);
    }

    private function freshSale(): void
    {
        $this->http->post('/pos/new', [], ['name="scan"', 'Scan']);
    }

    private function locatedId(PageResponse $page, string $pattern, string $label): string
    {
        return $this->http->id($page->effectiveUrl . "\n" . $page->body, $pattern, $label);
    }

    private function account(string $html, string $code): string
    {
        if (preg_match('/<option value="([0-9a-fA-F-]{36})">' . $code . ' /', $html, $match) !== 1) {
            $this->http->failures[] = 'account ' . $code . ' was not on the adjustment form';

            return '';
        }

        return strtolower($match[1]);
    }

    /**
     * @return array{start: string, end: string}
     */
    private function saleSpan(): array
    {
        $offset = hexdec(substr($this->tag(), 0, 4)) % 15000;
        $start = (new \DateTimeImmutable('2600-01-01'))->modify('+' . $offset . ' days');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $start->modify('+1 day')->format('Y-m-d'),
        ];
    }

    private function linkedId(string $html, string $prefix, string $label): string
    {
        $pattern = '#/' . preg_quote($prefix, '#') . '/([0-9a-fA-F-]{36})(?:\?[^"]*)?"[^>]*>\s*'
            . preg_quote($label, '#') . '#';

        return $this->http->id($html, $pattern, $label);
    }

    /**
     * @param list<array{id: string, floorId: string, x: int, z: int, width?: int, depth?: int}> $placed
     * @return array{x: int, z: int}
     */
    private function freeSpot(array $placed, string $floorId): array
    {
        for ($z = 0; $z <= 80; $z += 10) {
            for ($x = 0; $x <= 110; $x += 10) {
                $blocked = false;
                foreach ($placed as $row) {
                    if ($row['floorId'] !== $floorId) {
                        continue;
                    }
                    $width = $row['width'] ?? 10;
                    $depth = $row['depth'] ?? 10;
                    $apart = $x + 10 <= $row['x']
                        || $row['x'] + $width <= $x
                        || $z + 10 <= $row['z']
                        || $row['z'] + $depth <= $z;
                    if (!$apart) {
                        $blocked = true;
                        break;
                    }
                }
                if (!$blocked) {
                    return ['x' => $x, 'z' => $z];
                }
            }
        }

        return ['x' => 0, 'z' => 0];
    }

    private function input(string $html, string $id): string
    {
        $pattern = '/id="' . preg_quote($id, '/') . '"[^>]*value="([^"]*)"/';
        if (preg_match($pattern, $html, $match) !== 1) {
            return '';
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    private function safeId(string $html, string $tag, string $prefix, string $text, string $label): string
    {
        try {
            return strtolower(HtmlPage::taggedId($html, $tag, $prefix, $text));
        } catch (RuntimeException) {
            $this->http->failures[] = $label . ' was not on the page';

            return '';
        }
    }

    private function iso(string $date): string
    {
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date, $match) === 1) {
            return $match[3] . '-' . $match[1] . '-' . $match[2];
        }

        return $date;
    }

    /**
     * @return array<string, string>
     */
    private function zeroCounts(): array
    {
        $fields = [];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $fields['count_' . $key] = '0';
        }

        return $fields;
    }

    private function tempFile(string $name, string $contents): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc2-sweep-' . $this->tag() . '-' . $name;
        $written = file_put_contents($path, $contents);
        if ($written === false) {
            $this->http->failures[] = 'could not write ' . $name;

            return $path;
        }

        return $path;
    }

    private function skipSample(string $path): bool
    {
        if (str_contains($path, '01900000-0000-7000-8000-000000000001')) {
            return true;
        }
        if (str_contains($path, str_repeat('A', 43)) || str_contains($path, str_repeat('a', 64))) {
            return true;
        }

        return str_contains($path, '/vendor/') || str_ends_with($path, '/vendor');
    }

    private function tag(): string
    {
        return bin2hex(random_bytes(3));
    }

    /**
     * @return array{failures: list<string>, hits: list<array{method: string, path: string}>}
     */
    private function result(): array
    {
        return [
            'failures' => $this->http->failures,
            'hits' => $this->http->hits,
        ];
    }
}
