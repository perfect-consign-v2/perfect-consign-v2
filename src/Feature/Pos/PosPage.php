<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Ledger\Money;

final class PosPage
{
    public function __construct(
        private readonly MallApp $app,
        private readonly TicketStore $tickets,
        private readonly TicketTenders $held,
        private readonly GiftIssue $gifts,
        private readonly RegisterGate $gate,
        private readonly SaleHold $sales,
        private readonly SalePoster $poster,
        private readonly LayawayBook $layaways,
        private readonly PayoutStore $payouts,
    ) {
    }

    public function cart(string $slug, ?string $error): void
    {
        $this->render($slug, $error, $this->app->request()->query('instant') === '1');
    }

    public function instant(string $slug, ?string $error): void
    {
        $this->render($slug, null, true, null, null, $error ?? '');
    }

    public function gift(string $slug, string $error): void
    {
        $this->render($slug, null, false, $error);
    }

    public function payment(string $slug, string $error): void
    {
        $this->render($slug, null, false, null, $error);
    }

    private function render(string $slug, ?string $error, bool $reopenInstant, ?string $giftError = null, ?string $payError = null, string $instantError = ''): void
    {
        $tenant = $this->app->open($slug, true);
        $cart = $this->tickets->cart($this->gate->session($slug));
        $tenders = $this->held->list($cart['ticket_id']);
        $applied = $this->layaways->balance($cart['ticket_id']);
        foreach ($tenders as $tender) {
            $applied += $tender['amount'];
        }
        $request = $this->app->request();
        $giftNumber = trim($request->query('gift'));
        $held = $this->sales->id($slug);
        $gift = $this->giftForm($giftError, $reopenInstant);
        $pay = $this->payForm($payError);
        $instant = $this->instantForm($instantError);
        $this->app->show('Feature/Pos/pos', [
            'title' => 'Sell',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'showSearch' => false,
            'cart' => $cart,
            'tenders' => $tenders,
            'deposits' => $this->layaways->balance($cart['ticket_id']),
            'payments' => $this->layaways->payments($cart['ticket_id']),
            'shopperId' => $this->tickets->getShopper($cart['ticket_id'])['id'],
            'customerName' => $this->tickets->getShopper($cart['ticket_id'])['name'],
            'salesAvailable' => $cart['buyer_id'] === '' ? 0 : max(0, $this->payouts->openPayout($cart['buyer_id'])['payable']),
            'remaining' => $cart['due'] - $applied,
            'giftNumber' => $giftNumber,
            'giftBalance' => $giftNumber === '' ? null : $this->gifts->balance($giftNumber),
            'completed' => $held === '' ? null : $this->poster->receipt($held),
            'change' => $this->change($slug, $held),
            'error' => $error,
            'openInstant' => $gift['openInstant'],
            'openGift' => $gift['openGift'],
            'giftError' => $gift['giftError'],
            'giftFormNumber' => $gift['giftFormNumber'],
            'giftFormAmount' => $gift['giftFormAmount'],
            'giftFormTender' => $gift['giftFormTender'],
            'giftFormCheck' => $gift['giftFormCheck'],
            'giftCustom' => $gift['giftCustom'],
            'openPayment' => $pay['openPayment'],
            'payError' => $pay['payError'],
            'payCash' => $pay['payCash'],
            'payCard' => $pay['payCard'],
            'payCheck' => $pay['payCheck'],
            'payCheckNumber' => $pay['payCheckNumber'],
            'payVendor' => $pay['payVendor'],
            'payGift' => $pay['payGift'],
            'payGiftNumber' => $pay['payGiftNumber'],
            'payGiftCard' => $pay['payGiftCard'],
            'payGiftCardNumber' => $pay['payGiftCardNumber'],
            'payCredit' => $pay['payCredit'],
            'payCreditNumber' => $pay['payCreditNumber'],
            'instantError' => $instant['instantError'],
            'instantVendorId' => $instant['instantVendorId'],
            'instantVendorLabel' => $instant['instantVendorLabel'],
            'instantDescription' => $instant['instantDescription'],
            'instantPrice' => $instant['instantPrice'],
            'instantDiscountKind' => $instant['instantDiscountKind'],
            'instantDiscountValue' => $instant['instantDiscountValue'],
            'instantBrandId' => $instant['instantBrandId'],
            'instantBrandLabel' => $instant['instantBrandLabel'],
            'instantAttributes' => $instant['instantAttributes'],
            'instantAdded' => $request->query('added') === '1',
            'instantAddedPage' => $request->query('instant_added') === '1',
        ]);
    }

    /**
     * @return array{openInstant: bool, openGift: bool, giftError: string, giftFormNumber: string, giftFormAmount: string, giftFormTender: string, giftFormCheck: string, giftCustom: bool}
     */
    private function giftForm(?string $giftError, bool $reopenInstant): array
    {
        $request = $this->app->request();
        $form = [
            'openInstant' => $reopenInstant || $request->query('instant') === '1',
            'openGift' => false,
            'giftError' => '',
            'giftFormNumber' => '',
            'giftFormAmount' => '',
            'giftFormTender' => 'cash',
            'giftFormCheck' => '',
            'giftCustom' => false,
        ];
        if ($giftError === null) {
            return $form;
        }
        $form['openInstant'] = false;
        $form['openGift'] = true;
        $form['giftError'] = $giftError;
        $form['giftFormNumber'] = $request->field('gift_number');
        $form['giftFormAmount'] = $request->field('gift_amount');
        $form['giftFormCheck'] = $request->field('check_number');
        $form['giftCustom'] = $request->field('gift_custom') === '1';
        $tender = $request->field('gift_tender');
        if (in_array($tender, ['cash', 'card', 'checks'], true)) {
            $form['giftFormTender'] = $tender;
        }

        return $form;
    }

    /**
     * @return array{openPayment: bool, payError: string, payCash: string, payCard: string, payCheck: string, payCheckNumber: string, payVendor: string, payGift: string, payGiftNumber: string, payGiftCard: string, payGiftCardNumber: string, payCredit: string, payCreditNumber: string}
     */
    private function payForm(?string $payError): array
    {
        $request = $this->app->request();
        $form = [
            'openPayment' => false,
            'payError' => '',
            'payCash' => '',
            'payCard' => '',
            'payCheck' => '',
            'payCheckNumber' => '',
            'payVendor' => '',
            'payGift' => '',
            'payGiftNumber' => '',
            'payGiftCard' => '',
            'payGiftCardNumber' => '',
            'payCredit' => '',
            'payCreditNumber' => '',
        ];
        if ($payError === null) {
            return $form;
        }
        $form['openPayment'] = true;
        $form['payError'] = $payError;
        $form['payCash'] = $request->field('tender_cash');
        $form['payCard'] = $request->field('tender_card');
        $form['payCheck'] = $request->field('tender_checks');
        $form['payCheckNumber'] = $request->field('check_number');
        $form['payVendor'] = $request->field('tender_vendor_draw');
        $form['payGift'] = $request->field('tender_gift_certificate');
        $form['payGiftNumber'] = $request->field('gift_certificate_number');
        $form['payGiftCard'] = $request->field('tender_gift_card');
        $form['payGiftCardNumber'] = $request->field('gift_card_number');
        $form['payCredit'] = $request->field('tender_store_credit');
        $form['payCreditNumber'] = $request->field('store_credit_number');

        return $form;
    }

    /**
     * @return array{instantError: string, instantVendorId: string, instantVendorLabel: string, instantDescription: string, instantPrice: string, instantDiscountKind: string, instantDiscountValue: string, instantBrandId: string, instantBrandLabel: string, instantAttributes: array<string, array{id: string, label: string}>}
     */
    private function instantForm(string $instantError): array
    {
        $form = [
            'instantError' => '',
            'instantVendorId' => '',
            'instantVendorLabel' => '',
            'instantDescription' => '',
            'instantPrice' => '',
            'instantDiscountKind' => 'consignor_default',
            'instantDiscountValue' => '',
            'instantBrandId' => '',
            'instantBrandLabel' => '',
            'instantAttributes' => [],
        ];
        if ($instantError === '') {
            return $form;
        }
        $request = $this->app->request();
        $kind = $request->field('discount_kind');
        $attributes = [];
        foreach (NameList::all() as $list) {
            $attributes[$list->key] = [
                'id' => $request->field($list->key . '_id'),
                'label' => $request->field($list->key . '_label'),
            ];
        }
        $form['instantError'] = $instantError;
        $form['instantVendorId'] = $request->field('vendor_id');
        $form['instantVendorLabel'] = $request->field('vendor_label');
        $form['instantDescription'] = $request->field('description');
        $form['instantPrice'] = $request->field('price');
        $form['instantDiscountKind'] = in_array($kind, ['consignor_default', 'none', 'percent', 'flat'], true)
            ? $kind
            : 'consignor_default';
        $form['instantDiscountValue'] = $request->field('discount_value');
        $form['instantBrandId'] = $request->field('brand_id');
        $form['instantBrandLabel'] = $request->field('brand_label');
        $form['instantAttributes'] = $attributes;

        return $form;
    }

    private function change(string $slug, string $held): ?int
    {
        if ($held === '') {
            return null;
        }
        $tendered = $this->sales->tendered($slug);
        if ($tendered === '' || preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $tendered) !== 1) {
            return null;
        }
        $cash = $this->poster->receipt($held)['cash'];
        $handed = Money::parseMinor($tendered);
        if ($cash < 1 || $handed < $cash) {
            return null;
        }

        return $handed - $cash;
    }

    public function held(string $slug, string $status): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Pos/held', [
            'title' => $status === 'layaway' ? 'Layaways' : 'Suspended sales',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'showSearch' => false,
            'cart' => $this->tickets->cart($this->gate->session($slug)),
            'heldStatus' => $status,
            'layaways' => $status === 'layaway' ? $this->tickets->layaways() : [],
        ]);
    }
}
