<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class RegisterPageTest extends TestCase
{
    public function testAnEmptyRegisterWaitsForAScan(): void
    {
        $html = $this->page();

        self::assertStringContainsString('class="register-lock" >', $html);
        self::assertStringContainsString('Cart is empty.', $html);
        self::assertStringContainsString('autocomplete="off" autofocus', $html);
        self::assertStringContainsString('gift-balance-title" hidden', $html);
        self::assertStringNotContainsString('data-open-modal="customer"', $html);
        self::assertStringNotContainsString('id="discount"', $html);
        self::assertStringNotContainsString('id="tax-waived"', $html);
        self::assertStringNotContainsString('id="cash-round"', $html);
        self::assertStringNotContainsString('Layaway $', $html);
        self::assertStringNotContainsString('data-open-modal="enter-payment"', $html);
        self::assertStringNotContainsString('That certificate is not on file.', $html);
        self::assertStringNotContainsString('class="register-status"', $html);
        self::assertStringContainsString('id="instant_brand"', $html);
    }

    public function testAMallThatDoesNotUseBrandsOmitsTheBrandField(): void
    {
        $html = $this->page(['showBrand' => false, 'openInstant' => true]);

        self::assertStringNotContainsString('id="instant_brand"', $html);
        self::assertStringContainsString('id="instant_description"', $html);
    }

    public function testAnOpenSaleShowsTheCartAndTheAmountStillDue(): void
    {
        $html = $this->page([
            'remaining' => 500,
            'customerName' => 'Bea',
            'cart' => ['ticket_id' => 'ticket-1', 'lines' => [$this->line()]],
        ]);

        self::assertStringContainsString('data-open-modal="customer"', $html);
        self::assertStringContainsString('Customer: Bea', $html);
        self::assertStringContainsString('Clear customer', $html);
        self::assertStringContainsString('Selected: None', $html);
        self::assertStringNotContainsString('Clear vendor', $html);
        self::assertStringNotContainsString('value="loyalty" selected', $html);
        self::assertStringNotContainsString('value="resale" selected', $html);
        self::assertStringNotContainsString('Line discount −', $html);
        self::assertStringContainsString('Blue vase', $html);
        self::assertStringContainsString('data-open-modal="enter-payment"', $html);
        self::assertStringContainsString('class="register-lock" >', $html);
        self::assertStringNotContainsString('Cart is empty.', $html);
    }

    public function testAFinishedSaleLocksTheCounterAndCanBeVoided(): void
    {
        $html = $this->page([
            'completed' => $this->completed(),
        ]);

        self::assertStringContainsString('class="register-lock" disabled', $html);
        self::assertStringContainsString('Confirm void', $html);
        self::assertStringContainsString('>Void ticket</button>', $html);
        self::assertStringNotContainsString('id="discount"', $html);
        self::assertStringNotContainsString('id="tax-waived"', $html);
        self::assertStringNotContainsString('+$', $html);
        self::assertSame(2, substr_count($html, 'Scan and Instant inventory stay locked until you start a new sale.'));
    }

    public function testAFinishedSaleAddsTheCashRoundToTheTotal(): void
    {
        $html = $this->page([
            'completed' => $this->completed([
                'round' => 5,
                'lines' => [[
                    'item_id' => 'item-1',
                    'code' => 'TAG1',
                    'description' => 'Blue vase',
                    'vendor' => 'Ada',
                    'price' => 1000,
                    'quantity' => 2,
                    'discount' => 100,
                    'tax' => 50,
                    'returned' => false,
                ]],
            ]),
        ]);

        self::assertStringContainsString('+$0.05', $html);
        self::assertStringContainsString('$19.50', $html);
        self::assertStringContainsString('$19.55', $html);
        self::assertStringContainsString('>Return</button>', $html);
    }

    public function testAZeroQuantityStillChargesOneAndAReturnStaysHidden(): void
    {
        $zero = $this->page([
            'completed' => $this->completed([
                'lines' => [[
                    'item_id' => 'item-1',
                    'code' => 'TAG1',
                    'description' => 'Blue vase',
                    'vendor' => 'Ada',
                    'price' => 1000,
                    'quantity' => 0,
                    'discount' => 0,
                    'tax' => 0,
                    'returned' => false,
                ]],
            ]),
        ]);
        $returned = $this->page([
            'completed' => $this->completed([
                'lines' => [[
                    'item_id' => 'item-1',
                    'code' => 'TAG1',
                    'description' => 'Blue vase',
                    'vendor' => 'Ada',
                    'price' => 1000,
                    'quantity' => 1,
                    'discount' => 0,
                    'tax' => 0,
                    'returned' => true,
                ]],
            ]),
        ]);

        self::assertStringContainsString('register-due">$10.00', $zero);
        self::assertStringNotContainsString('>Return</button>', $returned);
    }

    public function testALineKeepsItsQuantityAndNetPrice(): void
    {
        $line = $this->line();
        $line['quantity'] = 2;
        $line['line_discount'] = 100;
        $line['line_discount_kind'] = 'amount';
        $html = $this->page([
            'cart' => [
                'ticket_id' => 'ticket-1',
                'lines' => [$line],
                'discount_reason' => 'loyalty',
                'waiver' => 'resale',
                'buyer_name' => 'Ada',
                'buyer_id' => 'vendor-1',
            ],
        ]);

        self::assertStringNotContainsString('data-qty-delta="-1" disabled', $html);
        self::assertStringContainsString('class="register-line-total"', $html);
        self::assertStringContainsString('id="summary-subtotal"', $html);
        self::assertStringContainsString('data-unit-price="1000"', $html);
        self::assertStringContainsString('Line discount −$1.00', $html);
        self::assertStringContainsString('$19.00', $html);
        self::assertSame(1, substr_count($html, 'value="percent" checked'));
        self::assertStringContainsString('value="amount" checked', $html);
        self::assertStringContainsString('value="loyalty" selected', $html);
        self::assertStringNotContainsString('value="damaged" selected', $html);
        self::assertStringContainsString('value="resale" selected', $html);
        self::assertStringContainsString('Selected: Ada', $html);
        self::assertStringContainsString('Clear vendor', $html);
    }

    public function testFinishSaleStaysOffUntilTheCartIsPaid(): void
    {
        $empty = $this->page([
            'cart' => ['ticket_id' => 'ticket-1'],
        ]);
        $drafts = $this->page([
            'cart' => ['ticket_id' => 'ticket-1', 'lines' => [$this->line()]],
            'drafts' => [[
                'id' => 'draft-1',
                'draft_no' => 1,
                'vendor_id' => 'vendor-1',
                'vendor_label' => 'Ada',
                'description' => 'Coat',
                'price_text' => '10.00',
                'brand_id' => '',
                'brand_label' => '',
                'discount_kind' => '',
                'discount_value' => '',
                'attributes' => [],
                'missing' => [],
            ]],
        ]);
        $owing = $this->page([
            'remaining' => 100,
            'cart' => ['ticket_id' => 'ticket-1', 'lines' => [$this->line()]],
        ]);
        $ready = $this->page([
            'cart' => ['ticket_id' => 'ticket-1', 'lines' => [$this->line()]],
        ]);

        self::assertStringContainsString('form="pos-pay" disabled', $empty);
        self::assertStringContainsString('form="pos-pay" disabled', $drafts);
        self::assertStringContainsString('form="pos-pay" disabled', $owing);
        self::assertStringContainsString('form="pos-pay">Finish sale', $ready);
    }

    public function testCashDueShowsBeforeATenderWhenTheRemainderIsNotANickel(): void
    {
        $open = [
            'ticket_id' => 'ticket-1',
            'rounding' => '5',
            'due' => 103,
        ];
        $owing = $this->page([
            'remaining' => 103,
            'cart' => $open,
        ]);
        $partialCash = $this->page([
            'remaining' => 103,
            'cart' => $open,
            'tenders' => [['role' => 'cash', 'amount' => 100, 'reference' => '']],
        ]);
        $card = $this->page([
            'remaining' => 103,
            'cart' => $open,
            'tenders' => [['role' => 'card', 'amount' => 100, 'reference' => '']],
        ]);
        $nickel = $this->page([
            'remaining' => 105,
            'cart' => $open,
        ]);
        $off = $this->page([
            'remaining' => 103,
            'cart' => ['ticket_id' => 'ticket-1', 'due' => 103],
        ]);
        $deposit = $this->page([
            'remaining' => 103,
            'deposits' => 100,
            'cart' => $open,
        ]);
        $finished = $this->page([
            'remaining' => 103,
            'completed' => $this->completed(),
            'cart' => $open,
        ]);

        self::assertStringContainsString('id="summary-cash-round-amount">+$0.02', $owing);
        self::assertStringContainsString('id="summary-cash-due-amount">$1.05', $owing);
        self::assertStringContainsString('register-due">$1.03', $owing);
        self::assertStringContainsString('data-cash-quote="1"', $owing);
        self::assertStringContainsString('id="summary-cash-due-amount">$1.05', $partialCash);
        self::assertStringNotContainsString('id="summary-cash-due"', $card);
        self::assertStringNotContainsString('id="summary-cash-due"', $nickel);
        self::assertStringNotContainsString('id="summary-cash-due"', $off);
        self::assertStringNotContainsString('id="summary-cash-due"', $deposit);
        self::assertStringNotContainsString('id="summary-cash-due"', $finished);
    }

    public function testNickelRoundingFillsThePayWindowOnlyBeforeADeposit(): void
    {
        $nickel = $this->page([
            'remaining' => 103,
            'cart' => ['ticket_id' => 'ticket-1', 'rounding' => '5'],
        ]);
        $deposit = $this->page([
            'remaining' => 103,
            'deposits' => 100,
            'cart' => ['ticket_id' => 'ticket-1', 'rounding' => '5'],
        ]);

        self::assertStringContainsString('data-round="5"', $nickel);
        self::assertStringContainsString('data-cash="1.05"', $nickel);
        self::assertStringContainsString('id="pay-settled"><span>Cash due</span><strong id="pay-settled-amount">$1.05', $nickel);
        self::assertStringContainsString('id="pay-round-amount">+$0.02', $nickel);
        self::assertStringContainsString('data-round="0"', $deposit);
        self::assertStringContainsString('data-cash="1.03"', $deposit);
        self::assertStringContainsString('id="pay-settled" hidden', $deposit);
        self::assertStringNotContainsString('id="summary-cash-due"', $deposit);
    }

    public function testADiscountAndAWaiverReplaceThePlainTaxLine(): void
    {
        $html = $this->page([
            'cart' => ['discount' => 250, 'discount_reason' => 'Friend', 'waiver' => 'Resale'],
        ]);

        self::assertStringContainsString('id="discount"', $html);
        self::assertStringContainsString('id="tax-waived"', $html);
        self::assertStringContainsString('Friend', $html);
        self::assertStringContainsString('Resale', $html);
    }

    public function testALayawayDepositListsWhatWasAlreadyPaid(): void
    {
        $html = $this->page([
            'deposits' => 150,
            'payments' => [['posted_on' => '2026-10-01', 'tender' => 'Cash', 'amount' => 150, 'kind' => 'Deposit']],
        ]);

        self::assertStringContainsString('Layaway $1.50', $html);
        self::assertStringContainsString('10-01-2026', $html);
    }

    public function testTheScannerYieldsWhileAGiftOrAPaymentIsOpen(): void
    {
        $gift = $this->page(['openGift' => true]);
        $payment = $this->page(['openPayment' => true]);

        self::assertStringNotContainsString('autocomplete="off" autofocus', $gift);
        self::assertStringNotContainsString('autocomplete="off" autofocus', $payment);
    }

    public function testTheSaleCardLeavesTheNoticeAndTheErrorToTheDialog(): void
    {
        $saved = $this->page(['notice' => 'Saved.', 'error' => null]);
        $boom = $this->page(['notice' => null, 'error' => 'Boom.']);
        $blankNotice = $this->page(['notice' => '', 'error' => 'Boom.']);
        $blankError = $this->page(['notice' => 'Saved.', 'error' => '']);
        $blank = $this->page(['notice' => '', 'error' => '']);

        self::assertStringNotContainsString('class="register-status"', $saved);
        self::assertStringNotContainsString('class="alert alert-success"', $saved);
        self::assertStringNotContainsString('Saved.', $saved);
        self::assertStringNotContainsString('class="register-status"', $boom);
        self::assertStringNotContainsString('class="alert alert-danger"', $boom);
        self::assertStringNotContainsString('Boom.', $boom);
        self::assertStringNotContainsString('class="alert alert-success"', $blankNotice);
        self::assertStringNotContainsString('class="alert alert-danger"', $blankError);
        self::assertStringNotContainsString('class="register-status"', $blank);
    }

    public function testEnterPaymentWaitsForAnOpenSaleThatStillOwesMoney(): void
    {
        $closed = $this->page(['remaining' => 500]);
        $paid = $this->page([
            'remaining' => 0,
            'cart' => ['ticket_id' => 'ticket-1'],
        ]);

        self::assertStringNotContainsString('data-open-modal="enter-payment"', $closed);
        self::assertStringNotContainsString('data-open-modal="enter-payment"', $paid);
    }

    public function testAnOfferAndAStoreDiscountComeOffTheLine(): void
    {
        $line = $this->line();
        $line['offer_name'] = 'Two for one';
        $line['offer_discount'] = 200;
        $line['store_discount'] = 50;
        $line['quantity'] = 2;
        $line['line_discount'] = 100;
        $html = $this->page([
            'remaining' => 1,
            'cart' => ['ticket_id' => 'ticket-1', 'lines' => [$line]],
        ]);
        $quiet = $this->line();
        $quiet['offer_name'] = '';
        $quiet['offer_discount'] = 200;
        $quiet['store_discount'] = 50;
        $named = $this->line();
        $named['offer_name'] = 'Two for one';
        $without = $this->page(['cart' => ['ticket_id' => 'ticket-1', 'lines' => [$quiet]]]);
        $zero = $this->page(['cart' => ['ticket_id' => 'ticket-1', 'lines' => [$named]]]);

        self::assertStringContainsString('Two for one −$2.50', $html);
        self::assertStringContainsString('>$16.50</td>', $html);
        self::assertStringNotContainsString('>$18.50</td>', $html);
        self::assertStringNotContainsString('>$21.50</td>', $html);
        self::assertStringNotContainsString('−$', $without);
        self::assertStringNotContainsString('Two for one', $zero);
    }

    public function testInstantInventoryFocusFollowsThePriceError(): void
    {
        $open = $this->page(['openInstant' => true, 'instantError' => '']);
        $price = $this->page(['openInstant' => true, 'instantError' => 'Enter a price.']);
        $closed = $this->page(['openInstant' => false]);
        $finished = $this->page([
            'completed' => $this->completed([
                'lines' => [[
                    'description' => 'Blue vase',
                    'code' => 'TAG1',
                    'vendor' => 'Ada',
                    'price' => 1000,
                    'quantity' => 2,
                    'discount' => 100,
                    'store_discount' => 50,
                    'tax' => 0,
                    'returned' => false,
                    'item_id' => 'item-1',
                ]],
            ]),
        ]);

        $added = $this->page(['openInstant' => true, 'instantAdded' => true]);
        $addedPage = $this->page(['instantAddedPage' => true]);

        self::assertStringContainsString('id="instant-error" role="alert" hidden', $open);
        self::assertStringContainsString('class="alert alert-success" role="status">Item added to cart.', $added);
        self::assertStringContainsString('class="alert alert-success" role="status">Item added to cart.', $addedPage);
        self::assertStringNotContainsString('Item added to cart.', $open);
        self::assertStringContainsString('placeholder="Name or vendor number" autofocus', $open);
        self::assertStringContainsString('value="consignor_default" selected', $open);
        self::assertStringNotContainsString('value="none" selected', $open);
        self::assertStringContainsString('>Enter a price.</p>', $price);
        self::assertStringNotContainsString('id="instant-error" role="alert" hidden', $price);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="instant-error" autofocus', $price);
        self::assertStringNotContainsString('placeholder="Name or vendor number" autofocus', $price);
        self::assertStringNotContainsString('placeholder="Name or vendor number" autofocus', $closed);
        self::assertStringContainsString('$18.50', $finished);
        self::assertStringContainsString('>Return</button>', $finished);
        self::assertStringNotContainsString('id="discount"', $finished);
        self::assertStringNotContainsString('id="tax-waived"', $finished);
        $voided = $this->page([
            'completed' => $this->completed([
                'voided' => true,
                'discount' => 0,
                'waiver' => 'Friend',
                'lines' => [[
                    'description' => 'Blue vase',
                    'code' => 'TAG1',
                    'vendor' => 'Ada',
                    'price' => 1000,
                    'quantity' => 1,
                    'discount' => 0,
                    'tax' => 0,
                    'returned' => false,
                    'item_id' => 'item-1',
                ]],
            ]),
        ]);
        self::assertStringNotContainsString('>Return</button>', $voided);
        self::assertStringContainsString('id="tax-waived"', $voided);
    }

    public function testAGiftNumberOpensItsBalance(): void
    {
        $missing = $this->page(['giftNumber' => 'GC1', 'giftBalance' => null]);
        $balance = $this->page(['giftNumber' => 'GC1', 'giftBalance' => 500]);

        self::assertStringNotContainsString('gift-balance-title" hidden', $missing);
        self::assertStringContainsString('That certificate is not on file.', $missing);
        self::assertStringContainsString('Balance left', $balance);
        self::assertStringContainsString('$5.00', $balance);
        self::assertStringContainsString('class="gift-lookup"', $balance);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function page(array $overrides = []): string
    {
        $cart = array_replace($this->cart(), is_array($overrides['cart'] ?? null) ? $overrides['cart'] : []);
        unset($overrides['cart']);
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Pos/pos', array_replace($this->frame(), $overrides, ['cart' => $cart]));
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(): array
    {
        return [
            'slug' => 'demo',
            'displayName' => 'Demo Mall',
            'csrf' => 'token',
            'activeRegister' => 'Front',
            'chooseRegister' => false,
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
            'giftFormKind' => 'gift_certificate',
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
            'notice' => null,
            'error' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cart(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(): array
    {
        return [
            'line_no' => 1,
            'item_id' => 'item-1',
            'code' => 'TAG1',
            'description' => 'Blue vase',
            'vendor' => 'Ada',
            'vendor_id' => 'vendor-1',
            'price' => 1000,
            'quantity' => 1,
            'commission' => 0,
            'tax' => 0,
            'discount' => 0,
            'line_discount' => 0,
            'line_discount_kind' => 'percent',
            'line_discount_value' => '',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function completed(array $overrides = []): array
    {
        return $overrides + [
            'journal_id' => 'journal-1',
            'sale_number' => 1,
            'posting_key' => 'S-1',
            'description' => '',
            'voided' => false,
            'lines' => [],
            'tenders' => [],
            'round' => 0,
            'cash' => 0,
            'discount' => 0,
            'discount_reason' => '',
            'waiver' => '',
        ];
    }
}
