<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Backup\BackupHeadline;
use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Feature\Backup\NightlyCopy;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Ledger\DatabaseRefusal;

final class MallController
{
    private ?string $postedCashRound = null;

    private ?string $postedTaxRate = null;

    /** @var array<string, bool>|null */
    private ?array $postedCatalogs = null;

    private ?string $postedStock = null;

    private ?string $postedFooter = null;

    private ?string $postedMailVia = null;

    private ?string $postedGmail = null;

    private MallRentDraft $rentDraft;

    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly MallPayoutSettings $payouts,
        private readonly BackupStore $backups,
        private readonly NightlyCopy $nightly,
        private readonly LabelCatalog $stocks,
        private readonly ReceiptMailSettings $receiptMail,
    ) {
        $this->rentDraft = new MallRentDraft($app, $payouts);
    }

    public function showError(string $slug, string $error): void
    {
        $this->app->open($slug, true);
        $booth = $this->mall->boothSize();
        $floor = $this->mall->floorSize();
        $this->form(
            $slug,
            $this->mall->timeZone(),
            $booth['width'],
            $booth['depth'],
            $floor['width'],
            $floor['depth'],
            MallMeasure::percent($this->mall->storeTake()),
            MallMeasure::dayText($this->mall->floorDays()),
            $error
        );
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings', ['GET'])]
    public function show(string $slug): void
    {
        $this->app->open($slug, true);
        $booth = $this->mall->boothSize();
        $floor = $this->mall->floorSize();
        $this->form(
            $slug,
            $this->mall->timeZone(),
            $booth['width'],
            $booth['depth'],
            $floor['width'],
            $floor['depth'],
            MallMeasure::percent($this->mall->storeTake()),
            MallMeasure::dayText($this->mall->floorDays()),
            null
        );
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $part = $this->app->request()->field('settings_part');
        if ($part === 'money') {
            $outcome = $this->rentDraft->money($this->mall)->apply($slug);
            $this->postedCashRound = $outcome->cashRound;
            $this->postedTaxRate = $outcome->taxRate;
            $booth = $this->mall->boothSize();
            $floor = $this->mall->floorSize();
            $this->form(
                $slug,
                $this->mall->timeZone(),
                $booth['width'],
                $booth['depth'],
                $floor['width'],
                $floor['depth'],
                $outcome->storeTake,
                MallMeasure::dayText($this->mall->floorDays()),
                $outcome->error,
            );

            return;
        }
        if ($part === 'building') {
            $this->saveBuilding($slug);

            return;
        }
        if ($part === 'receipt') {
            $this->saveReceipt($slug);

            return;
        }
        $this->postedStock = $this->app->request()->field('label_stock');
        $timeZone = $this->app->request()->field('time_zone');
        $width = MallMeasure::feet($this->app->request()->field('booth_width_ft'));
        $depth = MallMeasure::feet($this->app->request()->field('booth_depth_ft'));
        $floorWidth = MallMeasure::feet($this->app->request()->field('floor_width_ft'));
        $floorDepth = MallMeasure::feet($this->app->request()->field('floor_depth_ft'));
        $storeTake = $this->app->request()->field('default_store_take');
        $taxRate = $this->app->request()->field('tax_rate');
        $floorRaw = $this->app->request()->field('floor_days');
        $basis = MallMeasure::basisPoints($storeTake);
        $taxBasis = MallMeasure::taxMillis($taxRate);
        $days = MallMeasure::wholeDays($floorRaw);
        $rentError = $this->rentDraft->read();
        $error = $rentError ?? $this->refusal($basis, $taxBasis, $days, $timeZone, $width, $depth, $floorWidth, $floorDepth);
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/settings', 'Settings saved.');
        }
        $this->postedCashRound = $this->app->request()->field('cash_round_nickel') === '1' ? '1' : '';
        $this->postedTaxRate = $taxRate;
        $this->postedCatalogs = [
            'use_color' => $this->app->request()->field('use_color') === '1',
            'use_category' => $this->app->request()->field('use_category') === '1',
            'use_size' => $this->app->request()->field('use_size') === '1',
            'use_condition' => $this->app->request()->field('use_condition') === '1',
            'use_department' => $this->app->request()->field('use_department') === '1',
            'use_brand' => $this->app->request()->field('use_brand') === '1',
        ];
        $this->form($slug, $timeZone, $width ?? 0, $depth ?? 0, $floorWidth ?? 0, $floorDepth ?? 0, $storeTake, $floorRaw, $error);
    }

    private function saveReceipt(string $slug): void
    {
        $footer = $this->app->request()->field('receipt_footer');
        $this->postedFooter = $footer;
        $this->postedMailVia = $this->app->request()->field('receipt_mail_via');
        $this->postedGmail = $this->app->request()->field('gmail_address');
        try {
            $this->mall->setReceiptFooter($footer);
            $this->receiptMail->save(
                $this->postedMailVia,
                $this->postedGmail,
                $this->app->request()->raw('gmail_app_password'),
            );
        } catch (DatabaseRefusal $refusal) {
            $this->showError($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->showError($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/settings', 'Receipt saved.');
    }

    private function saveBuilding(string $slug): void
    {
        $this->postedStock = $this->app->request()->field('label_stock');
        $timeZone = $this->app->request()->field('time_zone');
        $width = MallMeasure::feet($this->app->request()->field('booth_width_ft'));
        $depth = MallMeasure::feet($this->app->request()->field('booth_depth_ft'));
        $floorWidth = MallMeasure::feet($this->app->request()->field('floor_width_ft'));
        $floorDepth = MallMeasure::feet($this->app->request()->field('floor_depth_ft'));
        $floorRaw = $this->app->request()->field('floor_days');
        $days = MallMeasure::wholeDays($floorRaw);
        $error = $this->buildingError($width, $depth, $floorWidth, $floorDepth, $days);
        if ($error === null && is_int($width) && is_int($depth) && is_int($floorWidth) && is_int($floorDepth) && $days !== false) {
            $error = $this->writeBuilding($timeZone, $width, $depth, $floorWidth, $floorDepth, $days);
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/settings', 'Building saved.');
        }
        $this->postedCatalogs = [
            'use_color' => $this->app->request()->field('use_color') === '1',
            'use_category' => $this->app->request()->field('use_category') === '1',
            'use_size' => $this->app->request()->field('use_size') === '1',
            'use_condition' => $this->app->request()->field('use_condition') === '1',
            'use_department' => $this->app->request()->field('use_department') === '1',
            'use_brand' => $this->app->request()->field('use_brand') === '1',
        ];
        $this->form($slug, $timeZone, $width ?? 0, $depth ?? 0, $floorWidth ?? 0, $floorDepth ?? 0, MallMeasure::percent($this->mall->storeTake()), $floorRaw, $error);
    }

    private function writeBuilding(
        string $timeZone,
        int $width,
        int $depth,
        int $floorWidth,
        int $floorDepth,
        ?int $days,
    ): ?string {
        if (!$this->payouts->consignmentEnabled() && $this->app->request()->field('consignment_enabled') !== '1') {
            $days = null;
        }
        try {
            $this->mall->save(
                $timeZone,
                MallWrite::posted($width, $depth, $floorWidth, $floorDepth, $this->requestFlags()),
                $this->mall->storeTake(),
                $days,
                $this->mall->cashRoundNickel() ? '1' : '',
                $this->mall->taxRate(),
            );
            $this->mall->setLabelStock($this->postedStock ?? $this->mall->getLabelStock());
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
    private function requestFlags(): array
    {
        return [
            'color' => $this->app->request()->field('use_color') === '1',
            'category' => $this->app->request()->field('use_category') === '1',
            'size' => $this->app->request()->field('use_size') === '1',
            'condition' => $this->app->request()->field('use_condition') === '1',
            'department' => $this->app->request()->field('use_department') === '1',
            'brand' => $this->app->request()->field('use_brand') === '1',
        ];
    }

    private function buildingError(?int $width, ?int $depth, ?int $floorWidth, ?int $floorDepth, int|false|null $days): ?string
    {
        if ($width === null || $depth === null) {
            return 'booth size is whole feet';
        }
        if ($floorWidth === null || $floorDepth === null) {
            return 'floor size is whole feet';
        }
        if ($days === false) {
            return 'days on the floor is a whole number from 1 to 365, or empty';
        }

        return $this->stickers();
    }

    private function refusal(
        ?int $basis,
        ?int $taxBasis,
        int|false|null $days,
        string $timeZone,
        ?int $width,
        ?int $depth,
        ?int $floorWidth,
        ?int $floorDepth,
    ): ?string {
        if ($basis === null) {
            return 'store take is a percent from 0 to 100';
        }
        if ($taxBasis === null) {
            return 'tax rate is a percent from 0 to 100';
        }
        if ($days === false) {
            return 'days on the floor is a whole number from 1 to 365, or empty';
        }
        if ($width === null || $depth === null) {
            return 'booth size is whole feet';
        }
        if ($floorWidth === null || $floorDepth === null) {
            return 'floor size is whole feet';
        }
        $stickers = $this->stickers();
        if ($stickers !== null) {
            return $stickers;
        }

        return $this->store($timeZone, $width, $depth, $floorWidth, $floorDepth, $basis, $days, $taxBasis);
    }

    private function stickers(): ?string
    {
        $stock = $this->postedStock ?? '';
        if ($stock === '') {
            $stock = $this->mall->getLabelStock();
            $this->postedStock = $stock;
        }
        if (!$this->stocks->isKnown($stock)) {
            return 'Choose the stickers this mall uses.';
        }

        return null;
    }

    private function store(
        string $timeZone,
        int $width,
        int $depth,
        int $floorWidth,
        int $floorDepth,
        int $storeTake,
        ?int $floorDays,
        int $taxRate,
    ): ?string {
        $consignment = $this->app->request()->field('consignment_enabled') === '1';
        if (!$consignment) {
            $floorDays = null;
        }
        try {
            $this->mall->save(
                $timeZone,
                MallWrite::posted(
                    $width,
                    $depth,
                    $floorWidth,
                    $floorDepth,
                    [
                        'color' => $this->app->request()->field('use_color') === '1',
                        'category' => $this->app->request()->field('use_category') === '1',
                        'size' => $this->app->request()->field('use_size') === '1',
                        'condition' => $this->app->request()->field('use_condition') === '1',
                        'department' => $this->app->request()->field('use_department') === '1',
                        'brand' => $this->app->request()->field('use_brand') === '1',
                    ],
                ),
                $storeTake,
                $floorDays,
                $this->app->request()->field('cash_round_nickel') === '1' ? '1' : '',
                $taxRate,
            );
            $this->mall->setLabelStock($this->postedStock ?? $this->mall->getLabelStock());
            $this->payouts->save(
                $this->app->request()->field('operating_mode'),
                $this->rentDraft->prepaid ?? false,
                $this->rentDraft->rentals ?? false,
                $this->rentDraft->cycleDay ?? 1,
                $consignment,
                $this->app->request()->field('store_stock_enabled') === '1',
            );
            $this->rentDraft->save();
        } catch (DatabaseRefusal $refusal) {
            return $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            return $errorValue->getMessage();
        }

        return null;
    }

    private function form(
        string $slug,
        string $timeZone,
        int $width,
        int $depth,
        int $floorWidth,
        int $floorDepth,
        string $storeTake,
        string $floorDays,
        ?string $error,
    ): void {
        $tenant = $this->app->open($slug, true);
        $late = $this->payouts->lateFee();
        $this->app->show('Feature/Mall/settings', [
            'title' => 'Settings',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'timeZone' => $timeZone,
            'timeZones' => MallMeasure::timeZones($timeZone),
            'boothWidth' => $width,
            'boothDepth' => $depth,
            'floorWidth' => $floorWidth,
            'floorDepth' => $floorDepth,
            'storeTake' => $storeTake,
            'floorDays' => $floorDays,
            'cashRound' => $this->postedCashRound ?? ($this->mall->cashRoundNickel() ? '1' : ''),
            'taxRate' => $this->postedTaxRate ?? MallMeasure::taxPercent($this->mall->taxRate()),
            'backupHeadline' => BackupHeadline::text(
                $this->backups->latest($tenant->id),
                BackupHeadline::now(),
            ),
            'nightlyHeadline' => $this->nightly->headline($tenant->schema),
            'operatingMode' => $this->payouts->operatingMode(),
            'payoutCadence' => $this->payouts->payoutCadence(),
            'rentPrepaid' => $this->rentDraft->prepaid ?? $this->payouts->rentPrepaid(),
            'boothRentals' => $this->rentDraft->rentals ?? $this->payouts->rentalsEnabled(),
            'consignmentEnabled' => $this->payouts->consignmentEnabled(),
            'storeStockEnabled' => $this->payouts->storeStockEnabled(),
            'cycleDay' => $this->rentDraft->cycleDay ?? $this->payouts->cycleDay(),
            'cycleFrozen' => $this->payouts->cycleFrozen(),
            'lateFees' => $this->rentDraft->lateFees ?? $late['enabled'],
            'lateGrace' => $this->rentDraft->lateGrace ?? $late['grace'],
            'lateFirst' => $this->rentDraft->dollars($this->rentDraft->lateFirst ?? $late['first']),
            'lateNext' => $this->rentDraft->dollars($this->rentDraft->lateNext ?? $late['next']),
            'labelStock' => $this->postedStock ?? $this->mall->getLabelStock(),
            'receiptFooter' => $this->postedFooter ?? $this->mall->getReceiptFooter(),
            'receiptMailVia' => $this->postedMailVia ?? $this->receiptMail->via(),
            'gmailAddress' => $this->postedGmail ?? $this->receiptMail->gmailAddress(),
            'gmailPasswordSaved' => $this->receiptMail->hasGmailPassword(),
            'stocks' => $this->stocks->choices(),
            'catalogs' => $this->postedCatalogs ?? [
                'use_color' => $this->mall->enabled('use_color'),
                'use_category' => $this->mall->enabled('use_category'),
                'use_size' => $this->mall->enabled('use_size'),
                'use_condition' => $this->mall->enabled('use_condition'),
                'use_department' => $this->mall->enabled('use_department'),
                'use_brand' => $this->mall->enabled('use_brand'),
            ],
            'error' => $error,
        ]);
    }
}
