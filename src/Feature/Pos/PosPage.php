<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Catalog\NameList;

final class PosPage
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PosRegister $register,
        private readonly PosCartView $cartView,
        private readonly SaleHold $sales,
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
        $this->paint($slug, $error, $reopenInstant, $giftError, $payError, $instantError);
    }

    private function paint(string $slug, ?string $error, bool $reopenInstant, ?string $giftError, ?string $payError, string $instantError): void
    {
        $tenant = $this->app->open($slug, true);
        $session = $this->register->session($slug);
        $cart = $this->register->cart($session);
        $drafts = $this->register->drafts($session);
        $tenders = $this->register->tenders($cart['ticket_id']);
        $applied = $this->register->deposits($cart['ticket_id']);
        foreach ($tenders as $tender) {
            $applied += $tender['amount'];
        }
        $held = $this->sales->id($slug);
        $panel = $this->cartView->salePanel($slug, $session, $cart, $drafts, $tenders, $applied, $held);
        $this->app->show('Feature/Pos/pos', array_merge(
            $this->cartView->chrome($tenant, $error),
            $panel,
            $this->giftForm($giftError, $reopenInstant),
            $this->payForm($payError),
            $this->instantForm($instantError)
        ));
    }

    /**
     * @return array{openInstant: bool, openGift: bool, giftError: string, giftFormNumber: string, giftFormAmount: string, giftFormTender: string, giftFormCheck: string, giftCustom: bool, giftFormToken: string}
     */
    private function giftForm(?string $giftError, bool $reopenInstant): array
    {
        $request = $this->app->request();
        $token = $request->field('form_token');
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(16));
        }
        $form = [
            'openInstant' => $reopenInstant || $request->query('instant') === '1',
            'openGift' => false,
            'giftError' => '',
            'giftFormNumber' => '',
            'giftFormAmount' => '',
            'giftFormTender' => 'cash',
            'giftFormCheck' => '',
            'giftCustom' => false,
            'giftFormToken' => $token,
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

    public function held(string $slug, string $status): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Pos/held', [
            'title' => $status === 'layaway' ? 'Layaways' : 'Suspended sales',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'showSearch' => false,
            'cart' => $this->register->cart($this->register->session($slug)),
            'heldStatus' => $status,
            'lastSale' => $this->sales->last($slug),
            'layaways' => $status === 'layaway' ? $this->register->layaways() : [],
        ]);
    }
}
