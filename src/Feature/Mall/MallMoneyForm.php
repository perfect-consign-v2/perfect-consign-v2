<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class MallMoneyForm
{
    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly MallPayoutSettings $payouts,
        private readonly MallRentDraft $rentDraft,
    ) {
    }

    public function apply(string $slug): MallMoneyOutcome
    {
        $storeTake = $this->app->request()->field('default_store_take');
        $taxRate = $this->app->request()->field('tax_rate');
        $basis = MallMeasure::basisPoints($storeTake);
        $taxBasis = MallMeasure::taxMillis($taxRate);
        $error = $this->problem($basis, $taxBasis);
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/settings', 'Money saved.');
        }

        return new MallMoneyOutcome(
            $storeTake,
            $taxRate,
            $this->app->request()->field('cash_round_nickel') === '1' ? '1' : '',
            $error,
        );
    }

    private function problem(?int $basis, ?int $taxBasis): ?string
    {
        $error = $this->rentDraft->read();
        if ($error === null && $basis === null) {
            return 'store take is a percent from 0 to 100';
        }
        if ($error === null && $taxBasis === null) {
            return 'tax rate is a percent from 0 to 100';
        }
        if ($error === null && is_int($basis) && is_int($taxBasis)) {
            return $this->write($basis, $taxBasis);
        }

        return $error;
    }

    private function write(int $basis, int $taxBasis): ?string
    {
        $booth = $this->mall->boothSize();
        $floor = $this->mall->floorSize();
        try {
            $this->mall->save(
                $this->mall->timeZone(),
                MallWrite::posted($booth['width'], $booth['depth'], $floor['width'], $floor['depth'], $this->storedFlags()),
                $basis,
                $this->mall->floorDays(),
                $this->app->request()->field('cash_round_nickel') === '1' ? '1' : '',
                $taxBasis,
            );
            $shop = $this->payouts->getPostedShop(
                $this->app->request()->field('shop_flags'),
                $this->app->request()->field('consignment_enabled'),
                $this->app->request()->field('store_stock_enabled'),
            );
            $this->payouts->save(
                $this->app->request()->field('operating_mode'),
                $this->rentDraft->prepaid ?? false,
                $this->rentDraft->rentals ?? false,
                $this->rentDraft->cycleDay ?? 1,
                $shop['consignment'],
                $shop['store'],
            );
            $this->rentDraft->save();
        } catch (DatabaseRefusal $refusal) {
            return $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            return $errorValue->getMessage();
        }

        return null;
    }

    /**
     * @return array{color: bool, category: bool, size: bool, condition: bool, department: bool, brand: bool}
     */
    private function storedFlags(): array
    {
        return [
            'color' => $this->mall->enabled('use_color'),
            'category' => $this->mall->enabled('use_category'),
            'size' => $this->mall->enabled('use_size'),
            'condition' => $this->mall->enabled('use_condition'),
            'department' => $this->mall->enabled('use_department'),
            'brand' => $this->mall->enabled('use_brand'),
        ];
    }
}
