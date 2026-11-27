<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Backup\BackupHeadline;
use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Feature\Backup\NightlyCopy;
use PerfectConsign\Feature\Inventory\LabelCatalog;

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

    private ?string $postedPortal = null;

    private ?string $postedInventory = null;

    private string $settingsPart = 'money';

    public function __construct(
        private readonly MallApp $app,
        private readonly MallSettings $mall,
        private readonly MallPayoutSettings $payouts,
        private readonly BackupStore $backups,
        private readonly NightlyCopy $nightly,
        private readonly LabelCatalog $stocks,
        private readonly MallRentDraft $rentDraft,
    ) {
    }

    public function showError(string $slug, string $error, string $part = 'backup'): void
    {
        $this->settingsPart = $this->knownPart($part);
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
        $this->app->redirect('/' . $slug . '/settings/money');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/(money|building|receipt|backup)', ['GET'])]
    public function showPart(string $slug, string $part): void
    {
        $this->settingsPart = $this->knownPart($part);
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

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/(money|building|receipt)', ['POST'])]
    public function savePart(string $slug, string $part): void
    {
        $this->settingsPart = $this->knownPart($part);
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        if ($this->settingsPart === 'money') {
            $this->saveMoney($slug);

            return;
        }
        if ($this->settingsPart === 'building') {
            $this->saveBuilding($slug);

            return;
        }
        $this->saveReceipt($slug);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $this->app->redirect(
            '/' . $slug . '/settings/money',
            null,
            'Save Money, Building, or Receipt from that Settings page.',
        );
    }

    private function saveMoney(string $slug): void
    {
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
    }

    private function saveReceipt(string $slug): void
    {
        $this->settingsPart = 'receipt';
        $footer = $this->app->request()->field('receipt_footer');
        $this->postedFooter = $footer;
        $mailVia = $this->app->request()->field('receipt_mail_via');
        $gmail = $this->app->request()->field('gmail_address');
        $this->postedMailVia = $mailVia;
        $this->postedGmail = $gmail;
        $error = $this->rentDraft->keep(function () use ($footer, $mailVia, $gmail): void {
            $this->mall->setReceiptFooter($footer);
            if ($mailVia !== '') {
                $this->mall->setReceiptMail(
                    $mailVia,
                    $gmail,
                    $this->app->request()->raw('gmail_app_password'),
                );
            }
        }, $this->app->open($slug, true)->id);
        if ($error !== null) {
            $this->showError($slug, $error, 'receipt');

            return;
        }
        $this->app->redirect('/' . $slug . '/settings/receipt', 'Receipt saved.');
    }

    private function saveBuilding(string $slug): void
    {
        $this->settingsPart = 'building';
        $this->postedPortal = $this->app->request()->field('vendor_portal_enabled');
        $this->postedInventory = $this->app->request()->field('vendor_inventory_enabled');
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
            $failed = $this->rentDraft->keep(function () use ($timeZone, $width, $depth, $floorWidth, $floorDepth, $days): void {
                $this->writeBuilding($timeZone, $width, $depth, $floorWidth, $floorDepth, $days);
            }, $this->app->open($slug, true)->id);
            if ($failed !== null) {
                $error = $failed;
            }
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/settings/building', 'Building saved.');
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
    ): void {
        $days = $this->payouts->getDaysWhenConsigned($days, $this->app->request()->field('consignment_enabled'));
        $this->mall->save(
            $timeZone,
            MallWrite::posted($width, $depth, $floorWidth, $floorDepth, $this->requestFlags()),
            $this->mall->storeTake(),
            $days,
            $this->mall->getCashRoundText(),
            $this->mall->taxRate(),
            $this->app->request()->field('vendor_weekly_mail_enabled') === '1',
        );
        $this->mall->setLabelStock($this->postedStock ?? $this->mall->getLabelStock());
    }

    /**
     * @return array{color: bool, category: bool, size: bool, condition: bool, department: bool, brand: bool, portal: bool, inventory: bool}
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
            'portal' => $this->app->request()->field('vendor_portal_enabled') === '1',
            'inventory' => $this->app->request()->field('vendor_portal_enabled') === '1'
                && $this->app->request()->field('vendor_inventory_enabled') === '1',
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
        $late = $this->payouts->getLateFee();
        $vendor = $this->vendorForm();
        $this->app->show('Feature/Mall/settings', [
            'title' => 'Settings',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'settingsPart' => $this->settingsPart,
            'timeZone' => $timeZone,
            'timeZones' => MallMeasure::timeZones($timeZone),
            'boothWidth' => $width,
            'boothDepth' => $depth,
            'floorWidth' => $floorWidth,
            'floorDepth' => $floorDepth,
            'storeTake' => $storeTake,
            'floorDays' => $floorDays,
            'cashRound' => $this->postedCashRound ?? $this->mall->getCashRoundText(),
            'taxRate' => $this->postedTaxRate ?? MallMeasure::taxPercent($this->mall->taxRate()),
            'backupHeadline' => BackupHeadline::text(
                $this->backups->latest($tenant->id),
                BackupHeadline::now(),
            ),
            'nightlyHeadline' => $this->nightly->headline($tenant->schema),
            'operatingMode' => $this->payouts->getOperatingMode(),
            'payoutCadence' => $this->payouts->getPayoutCadence(),
            'rentPrepaid' => $this->rentDraft->prepaid ?? $this->payouts->isRentPrepaid(),
            'boothDeposits' => $this->rentDraft->deposits ?? $this->payouts->isDepositsEnabled(),
            'boothRentals' => $this->rentDraft->rentals ?? $this->payouts->isRentalsEnabled(),
            'consignmentEnabled' => $this->payouts->getShop()['consignment'],
            'storeStockEnabled' => $this->payouts->getShop()['store'],
            'cycleDay' => $this->rentDraft->cycleDay ?? $this->payouts->getCycleDay(),
            'cycleFrozen' => $this->payouts->isCycleFrozen(),
            'lateFees' => $this->rentDraft->lateFees ?? $late['enabled'],
            'lateGrace' => $this->rentDraft->lateGrace ?? $late['grace'],
            'lateFirst' => $this->rentDraft->dollars($this->rentDraft->lateFirst ?? $late['first']),
            'lateNext' => $this->rentDraft->dollars($this->rentDraft->lateNext ?? $late['next']),
            'requireSecondSignature' => $this->payouts->isSecondSignatureRequired(),
            'labelStock' => $this->postedStock ?? $this->mall->getLabelStock(),
            'receiptFooter' => $this->postedFooter ?? $this->mall->getReceiptFooter(),
            'receiptMailVia' => $this->postedMailVia ?? $this->mall->getReceiptMailVia(),
            'gmailAddress' => $this->postedGmail ?? $this->mall->getGmailAddress(),
            'gmailPasswordSaved' => $this->mall->hasGmailPassword(),
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
            'vendorPortal' => $vendor['portal'],
            'portalInventory' => $vendor['inventory'],
            'weeklyMail' => $vendor['weekly'],
        ]);
    }

    /**
     * @return array{portal: bool, inventory: bool, weekly: bool}
     */
    private function vendorForm(): array
    {
        if ($this->postedPortal !== null && $this->postedInventory !== null) {
            $open = $this->postedPortal === '1';

            return [
                'portal' => $open,
                'inventory' => $open && $this->postedInventory === '1',
                'weekly' => $this->app->request()->field('vendor_weekly_mail_enabled') === '1',
            ];
        }
        $stored = $this->mall->getVendorFlags();

        return [
            'portal' => $stored['portal'],
            'inventory' => $stored['portal'] && $stored['inventory'],
            'weekly' => $stored['weekly'],
        ];
    }

    private function knownPart(string $part): string
    {
        if (in_array($part, ['money', 'building', 'receipt', 'backup'], true)) {
            return $part;
        }

        return 'money';
    }
}
