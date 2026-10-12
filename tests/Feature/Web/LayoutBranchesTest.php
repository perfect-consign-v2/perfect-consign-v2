<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class LayoutBranchesTest extends TestCase
{
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
        self::assertStringContainsString('Month', $html);
        self::assertStringContainsString('Cashiers', $html);
        self::assertStringContainsString('Owner', $html);
        self::assertStringContainsString('href="/demo/colors"', $html);
        self::assertStringNotContainsString('Missing path', $html);
        self::assertStringNotContainsString('href="/demo/sizes"', $html);
        self::assertStringNotContainsString('href="/demo/conditions"', $html);
        self::assertStringContainsString('href="/demo/dashboard" aria-current="page"', $html);
        self::assertStringContainsString('Active: Front', $html);
        self::assertStringContainsString('class="alert alert-success"', $html);
        self::assertStringContainsString('Saved.', $html);
        self::assertStringContainsString('class="alert alert-warning"', $html);
        self::assertStringContainsString('Watch.', $html);
        self::assertStringContainsString('class="alert alert-danger"', $html);
        self::assertStringContainsString('Boom.', $html);
        self::assertStringContainsString('sidebar-scrim', $html);
        self::assertStringNotContainsString('id="prior-register"', $html);
        self::assertStringNotContainsString('class="pos-app"', $html);
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
        self::assertStringNotContainsString('Month', $html);
        self::assertStringNotContainsString('Cashiers', $html);
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

        self::assertStringNotContainsString('class="alert alert-success"', $html);
        self::assertStringNotContainsString('class="alert alert-warning"', $html);
        self::assertStringNotContainsString('class="alert alert-danger"', $html);
    }

    public function testAModalHidesThePageError(): void
    {
        $html = $this->layout([
            'error' => 'Boom.',
            'modal' => 'pay',
        ]);

        self::assertStringNotContainsString('class="alert alert-danger"', $html);
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

        self::assertSame(1, substr_count($html, 'class="alert alert-success"'));
        self::assertSame(1, substr_count($html, 'class="alert alert-danger"'));
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
