<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class LayoutBranchesTest extends TestCase
{
    public function testAMallThatDoesNotUseBrandsHidesThatPage(): void
    {
        $html = $this->layout([
            'showBrand' => false,
        ]);

        self::assertStringNotContainsString('href="/demo/brands"', $html);
        self::assertStringContainsString('People', $html);
        self::assertStringNotContainsString('Active:', $html);
    }

    public function testTheOwnerShellShowsTheNoticeAndTheCurrentPage(): void
    {
        $html = $this->layout([
            'displayName' => 'Demo Mall',
            'actorRole' => 'owner',
            'path' => '/demo/dashboard',
            'robots' => 'noindex',
            'notice' => 'Saved.',
            'warning' => 'Watch.',
            'error' => 'Boom.',
            'activeRegister' => 'Front',
            'catalogNav' => [
                ['/colors', 'Colors', 'i-tag'],
                ['', 'Missing path', 'i-tag'],
                ['/sizes', '', 'i-tag'],
                ['/conditions', 'Conditions', ''],
            ],
        ]);

        self::assertStringContainsString('<title>Demo · Demo Mall</title>', $html);
        self::assertStringContainsString('>D</span>', $html);
        self::assertStringContainsString('content="noindex"', $html);
        self::assertStringContainsString('This month', $html);
        self::assertStringContainsString('People', $html);
        self::assertStringContainsString('href="/demo/brands"', $html);
        self::assertStringContainsString('Owner', $html);
        self::assertStringContainsString('href="/demo/colors"', $html);
        self::assertStringNotContainsString('Missing path', $html);
        self::assertStringNotContainsString('href="/demo/sizes"', $html);
        self::assertStringNotContainsString('href="/demo/conditions"', $html);
        self::assertStringContainsString('href="/demo/dashboard" aria-current="page"', $html);
        self::assertStringContainsString('href="/demo/registers">Active: Front</a>', $html);
        self::assertStringNotContainsString('Reset Test Data', $html);
        self::assertStringContainsString('id="flash-dialog"', $html);
        self::assertStringContainsString('data-kind="danger"', $html);
        self::assertStringNotContainsString('class="alert alert-success"', $html);
        self::assertStringNotContainsString('Saved.', $html);
        self::assertStringNotContainsString('class="alert alert-warning"', $html);
        self::assertStringNotContainsString('Watch.', $html);
        self::assertStringContainsString('alert alert-danger flash-panel', $html);
        self::assertStringContainsString('<p class="error">Boom.</p>', $html);
        self::assertStringContainsString('sidebar-scrim', $html);
        self::assertStringNotContainsString('id="prior-register"', $html);
        self::assertStringNotContainsString('class="pos-app"', $html);
    }

    public function testAConsignmentStoreDropsVendorAndBoothPages(): void
    {
        $html = $this->layout([
            'actorRole' => 'owner',
            'consignmentOnly' => true,
            'boothRentals' => false,
            'path' => '/demo/dashboard',
        ]);

        self::assertStringContainsString('Consignors', $html);
        self::assertStringContainsString('Pay consignors', $html);
        self::assertStringContainsString('Consignor memo', $html);
        self::assertStringNotContainsString('>Vendors<', $html);
        self::assertStringNotContainsString('Pay vendors', $html);
        self::assertStringNotContainsString('Vendor memo', $html);
        self::assertStringNotContainsString('href="/demo/booths"', $html);
        self::assertStringNotContainsString('href="/demo/rent"', $html);
        self::assertStringNotContainsString('href="/demo/map"', $html);
        self::assertStringNotContainsString('href="/demo/wait-list"', $html);
        self::assertStringNotContainsString('href="/demo/mall-sale"', $html);
        self::assertStringNotContainsString('href="/demo/booth-extras"', $html);
        self::assertStringContainsString('href="/demo/parties"', $html);
        self::assertStringContainsString('href="/demo/payouts"', $html);
    }

    public function testTheDevOwnerSeesResetTestData(): void
    {
        putenv('STALL_ALLOW_TENANT_RESET=1');
        try {
            $html = $this->layout([
                'slug' => 'dev',
                'actorRole' => 'owner',
                'path' => '/dev/dashboard',
                'activeRegister' => 'Front',
            ]);
        } finally {
            putenv('STALL_ALLOW_TENANT_RESET');
        }

        self::assertStringContainsString('action="/dev/reset-test-data"', $html);
        self::assertStringContainsString('class="btn btn-danger"', $html);
        self::assertStringContainsString('name="csrf" value="token"', $html);
        self::assertStringContainsString('Reset Test Data', $html);
        self::assertStringContainsString('href="/dev/registers">Active: Front</a>', $html);
    }

    public function testTheMallOwnerSeesResetTestData(): void
    {
        putenv('STALL_ALLOW_TENANT_RESET=1');
        try {
            $html = $this->layout([
                'slug' => 'mall',
                'actorRole' => 'owner',
                'path' => '/mall/dashboard',
                'activeRegister' => 'Front',
            ]);
        } finally {
            putenv('STALL_ALLOW_TENANT_RESET');
        }

        self::assertStringContainsString('action="/mall/reset-test-data"', $html);
        self::assertStringContainsString('class="btn btn-danger"', $html);
        self::assertStringContainsString('Reset Test Data', $html);
        self::assertStringContainsString('href="/mall/registers">Active: Front</a>', $html);
    }

    public function testACashierOnTheDevMallDoesNotSeeResetTestData(): void
    {
        $html = $this->layout([
            'slug' => 'dev',
            'actorRole' => 'cashier',
            'path' => '/dev/pos',
            'activeRegister' => 'Front',
        ]);

        self::assertStringContainsString('href="/dev/registers">Active: Front</a>', $html);
        self::assertStringNotContainsString('Reset Test Data', $html);
        self::assertStringNotContainsString('/dev/reset-test-data', $html);
    }

    public function testTheVendorCheckAndAChildInventoryPageStayCurrent(): void
    {
        $vendor = $this->layout([
            'actorRole' => 'vendor',
            'path' => '/demo/vendor',
        ]);
        $checks = $this->layout([
            'actorRole' => 'vendor',
            'path' => '/demo/vendor/checks/1',
        ]);
        $sales = $this->layout([
            'actorRole' => 'vendor',
            'path' => '/demo/vendor/sales',
        ]);
        $inventory = $this->layout([
            'actorRole' => 'owner',
            'path' => '/demo/inventory/items',
        ]);

        self::assertStringContainsString('href="/demo/vendor" aria-current="page"', $vendor);
        self::assertStringNotContainsString('href="/demo/vendor/sales" aria-current="page"', $vendor);
        self::assertStringContainsString('href="/demo/vendor" aria-current="page"', $checks);
        self::assertStringNotContainsString('href="/demo/vendor/sales" aria-current="page"', $checks);
        self::assertStringContainsString('href="/demo/vendor/sales" aria-current="page"', $sales);
        self::assertStringNotContainsString('href="/demo/vendor" aria-current="page"', $sales);
        self::assertStringContainsString('href="/demo/inventory" aria-current="page"', $inventory);
        self::assertStringNotContainsString('href="/demo/dashboard" aria-current="page"', $inventory);
    }

    public function testANestedCatalogPageStaysInThatSection(): void
    {
        $html = $this->layout([
            'path' => '/demo/colors/red',
            'catalogNav' => [
                ['/colors', 'Colors', 'i-tag'],
            ],
        ]);

        self::assertStringContainsString('href="/demo/colors" aria-current="page"', $html);
    }

    public function testAMissingRoleShowsNoOwnerNavigation(): void
    {
        $html = $this->shell([]);

        self::assertStringNotContainsString('nav-label">Sell', $html);
        self::assertStringNotContainsString('This month', $html);
        self::assertStringNotContainsString('People', $html);
        self::assertStringNotContainsString('Close the year', $html);
        self::assertStringNotContainsString('>Owner<', $html);
    }

    public function testARoleThatIsNotTextShowsNoOwnerNavigation(): void
    {
        $html = $this->shell(['actorRole' => ['owner']]);

        self::assertStringNotContainsString('nav-label">Sell', $html);
        self::assertStringNotContainsString('>Owner<', $html);
    }

    public function testAnEmptyNameUsesTheFallbackMark(): void
    {
        $html = $this->layout([
            'displayName' => '',
            'title' => 'Demo',
        ]);

        self::assertStringContainsString('<title>Demo</title>', $html);
        self::assertStringContainsString('>P</span>', $html);
        self::assertStringNotContainsString('class="alert alert-success"', $html);
        self::assertStringNotContainsString('class="alert alert-warning"', $html);
        self::assertStringNotContainsString('class="alert alert-danger"', $html);
    }

    public function testABlankNoticeWarningAndErrorStayHidden(): void
    {
        $html = $this->layout([
            'notice' => '',
            'warning' => '',
            'error' => '',
        ]);

        self::assertStringNotContainsString('data-kind="success"', $html);
        self::assertStringNotContainsString('data-kind="warning"', $html);
        self::assertStringNotContainsString('data-kind="danger"', $html);
    }

    public function testAModalErrorAndAnInstantErrorOpenTheDangerDialog(): void
    {
        $modal = $this->layout(['modalError' => 'Drawer is short.']);
        $instant = $this->layout(['instantError' => 'That price is not a price.']);
        $blank = $this->layout(['modalError' => '', 'instantError' => '']);

        self::assertStringContainsString('data-kind="danger"', $modal);
        self::assertStringContainsString('Drawer is short.', $modal);
        self::assertStringContainsString('data-kind="danger"', $instant);
        self::assertStringContainsString('That price is not a price.', $instant);
        self::assertStringNotContainsString('data-kind="danger"', $blank);
    }

    public function testPreviewLinksStayOnTheVendorAndTheRegisterStaysHidden(): void
    {
        $vendor = '01900000-0000-7000-8000-0000000000aa';
        $html = $this->layout([
            'previewAs' => $vendor,
            'activeRegister' => 'Front',
            'chooseRegister' => true,
        ]);

        self::assertStringContainsString('/demo/vendor?as=' . $vendor, $html);
        self::assertStringContainsString('looking at this vendor\'s portal', $html);
        self::assertStringNotContainsString('Active: Front', $html);
        self::assertStringNotContainsString('Choose a register', $html);
    }

    public function testAnOpenRegisterChoiceIsShownWhenNothingIsActive(): void
    {
        $html = $this->layout([
            'chooseRegister' => true,
            'activeRegister' => '',
        ]);

        self::assertStringContainsString('Choose a register', $html);
        self::assertStringNotContainsString('looking at this vendor\'s portal', $html);
    }

    public function testAModalStillShowsThePageError(): void
    {
        $html = $this->layout([
            'error' => 'Boom.',
            'modal' => 'pay',
        ]);

        self::assertStringContainsString('id="flash-dialog"', $html);
        self::assertStringContainsString('data-kind="danger"', $html);
        self::assertStringContainsString('<p class="error">Boom.</p>', $html);
    }

    public function testANoticeOpensTheSuccessDialog(): void
    {
        $html = $this->layout(['notice' => 'Saved.']);

        self::assertStringContainsString('data-kind="success"', $html);
        self::assertStringContainsString('alert alert-success flash-panel', $html);
        self::assertStringContainsString('<p class="notice">Saved.</p>', $html);
        self::assertStringNotContainsString('class="alert alert-danger"', $html);
    }

    public function testAWarningOpensTheWarningDialog(): void
    {
        $html = $this->layout(['warning' => 'Watch.']);

        self::assertStringContainsString('data-kind="warning"', $html);
        self::assertStringContainsString('alert alert-warning flash-panel', $html);
        self::assertStringContainsString('<p class="warning">Watch.</p>', $html);
        self::assertStringNotContainsString('class="alert alert-danger"', $html);
    }

    public function testASignInErrorOpensTheDialog(): void
    {
        $html = $this->layout([
            'nav' => false,
            'displayName' => '',
            'body' => 'Shared/Web/root',
            'error' => null,
            'mall' => 'missing',
            'mallError' => 'No such mall.',
        ]);

        self::assertStringContainsString('data-kind="danger"', $html);
        self::assertStringContainsString('<p class="error">No such mall.</p>', $html);
        self::assertStringNotContainsString('role="alert"', $html);
    }

    public function testAnOpenRegisterFromTodayKeepsTheMenu(): void
    {
        $html = $this->layout([
            'leftOpen' => [['id' => 'reg-1', 'name' => 'Front', 'opened' => 'Oct 8']],
            'priorCloseStarted' => false,
        ]);

        self::assertStringContainsString('Sell', $html);
        self::assertStringNotContainsString('Close the register', $html);
    }

    public function testAPriorRegisterReplacesTheMenuUntilItIsClosed(): void
    {
        $html = $this->layout([
            'leftOpen' => [['id' => 'reg-1', 'name' => 'Front', 'opened' => 'Oct 5']],
            'priorCloseStarted' => true,
            'priorPrompt' => true,
        ]);

        self::assertStringContainsString('Close the register', $html);
        self::assertStringContainsString('id="prior-register"', $html);
        self::assertStringContainsString('Front', $html);
        self::assertStringContainsString('Oct 5', $html);
        self::assertStringNotContainsString('nav-label">Sell', $html);
    }

    public function testAnEmptyPriorListStillShowsTheMenu(): void
    {
        $html = $this->layout([
            'leftOpen' => [],
            'priorCloseStarted' => true,
            'priorPrompt' => false,
        ]);

        self::assertStringContainsString('nav-label">Sell', $html);
        self::assertStringNotContainsString('Close the register', $html);
        self::assertStringNotContainsString('id="prior-register"', $html);
    }

    public function testBooksNavigationAndAPosShellCloseTheLayout(): void
    {
        $books = $this->layout([
            'actorRole' => 'books',
            'path' => '/demo/sales',
        ]);
        $pos = $this->layout([
            'posShell' => true,
            'path' => '/demo/pos',
        ]);
        $oddPrior = $this->layout([
            'leftOpen' => ['not-a-row', ['id' => 'reg-1', 'name' => 'Front', 'opened' => 'Oct 5']],
            'priorPrompt' => true,
        ]);

        self::assertStringContainsString('>Books<', $books);
        self::assertStringContainsString('href="/demo/sales"', $books);
        self::assertStringContainsString('href="/demo/journals"', $books);
        self::assertStringContainsString('View payouts', $books);
        self::assertStringNotContainsString('People', $books);
        self::assertStringContainsString('class="pos-app"', $pos);
        self::assertStringContainsString('class="pos-main"', $pos);
        self::assertStringContainsString('id="prior-register"', $oddPrior);
        self::assertStringContainsString('Front', $oddPrior);
    }

    public function testTheRegisterPageDoesNotRepeatTheNoticeAboveTheSale(): void
    {
        $html = $this->layout([
            'nav' => false,
            'posShell' => false,
            'body' => 'Feature/Pos/pos',
            'notice' => 'Saved.',
            'error' => 'Boom.',
            'cart' => [
                'ticket_id' => '',
                'lines' => [],
                'merchandise' => 0,
                'discount' => 0,
                'discount_reason' => '',
                'tax' => 0,
                'tax_bps' => 0,
                'waiver' => '',
                'due' => 0,
                'cash_round' => 0,
                'cash_due' => 0,
                'rounding' => '',
                'buyer_id' => '',
                'buyer_name' => '',
                'parked' => [],
            ],
            'tenders' => [],
            'deposits' => 0,
            'payments' => [],
            'remaining' => 0,
            'salesAvailable' => 0,
            'completed' => null,
            'change' => null,
            'openInstant' => false,
            'openGift' => false,
            'giftError' => '',
            'giftFormNumber' => '',
            'giftFormAmount' => '',
            'giftFormTender' => 'cash',
            'giftFormCheck' => '',
            'giftCustom' => false,
            'openPayment' => false,
            'payError' => '',
            'payCash' => '',
            'payCard' => '',
            'payCheck' => '',
            'payCheckNumber' => '',
            'payVendor' => '',
            'payGift' => '',
            'payGiftNumber' => '',
            'payCredit' => '',
            'payCreditNumber' => '',
            'attributeLists' => [],
            'giftNumber' => '',
            'giftBalance' => null,
        ]);

        self::assertStringNotContainsString('class="register-status"', $html);
        self::assertSame(0, substr_count($html, 'alert alert-success flash-panel'));
        self::assertSame(1, substr_count($html, 'alert alert-danger flash-panel'));
        self::assertStringContainsString('<p class="error">Boom.</p>', $html);
        self::assertStringNotContainsString('Saved.', $html);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function shell(array $data): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Shared/Web/layout', $data + [
            'title' => 'Demo',
            'displayName' => 'Demo Mall',
            'slug' => 'demo',
            'nav' => true,
            'csrf' => 'token',
            'error' => null,
            'notice' => null,
            'warning' => null,
            'body' => 'Shared/Web/not-found',
            'path' => '/demo/dashboard',
            'robots' => '',
            'activeRegister' => '',
            'chooseRegister' => false,
            'leftOpen' => [],
            'priorCloseStarted' => false,
            'priorPrompt' => false,
            'catalogNav' => [],
            'posShell' => false,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function testADemoOwnerDoesNotSeeResetTestData(): void
    {
        putenv('STALL_ALLOW_TENANT_RESET=1');
        try {
            $html = $this->layout([
                'slug' => 'demo',
                'actorRole' => 'owner',
                'path' => '/demo/dashboard',
            ]);
        } finally {
            putenv('STALL_ALLOW_TENANT_RESET');
        }

        self::assertStringNotContainsString('Reset Test Data', $html);
    }

    public function testAStoreWithoutBoothsHidesThosePages(): void
    {
        $html = $this->layout([
            'boothRentals' => false,
            'consignmentOnly' => false,
            'actorRole' => 'owner',
            'path' => '/demo/dashboard',
        ]);

        self::assertStringNotContainsString('href="/demo/booths"', $html);
        self::assertStringNotContainsString('href="/demo/rent"', $html);
    }

    private function layout(array $overrides): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Shared/Web/layout', $overrides + [
            'title' => 'Demo',
            'displayName' => 'Demo Mall',
            'slug' => 'demo',
            'nav' => true,
            'csrf' => 'token',
            'error' => null,
            'notice' => null,
            'warning' => null,
            'body' => 'Shared/Web/not-found',
            'path' => '/demo/dashboard',
            'robots' => '',
            'activeRegister' => '',
            'chooseRegister' => false,
            'leftOpen' => [],
            'priorCloseStarted' => false,
            'priorPrompt' => false,
            'catalogNav' => [],
            'actorRole' => 'owner',
            'posShell' => false,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
