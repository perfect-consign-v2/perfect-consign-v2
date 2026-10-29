<?php

declare(strict_types=1);

use PerfectConsign\Feature\Mall\ZoneName;
use PerfectConsign\Feature\Rent\RentCycle;
use PerfectConsign\Shared\Http\View;

$boothRentals = ($boothRentals ?? true) === true;
$operatingMode = is_string($operatingMode ?? null) ? $operatingMode : '';
$consignmentOnly = $operatingMode === 'consignor';
$boothMall = $operatingMode === 'booth_mall';
$showRent = !$consignmentOnly && $boothRentals;
$cycleDay = is_int($cycleDay ?? null) && $cycleDay >= 1 && $cycleDay <= 28 ? $cycleDay : 1;
$cycleFrozen = ($cycleFrozen ?? false) === true;
$rentSentence = $showRent ? (new RentCycle($cycleDay))->sentence(!empty($rentPrepaid)) : '';

/** @var string $slug */
/** @var string $csrf */
/** @var string $timeZone */
/** @var list<string> $timeZones */
/** @var int $boothWidth */
/** @var int $boothDepth */
/** @var int $floorWidth */
/** @var int $floorDepth */
/** @var string $storeTake */
/** @var string $floorDays */
/** @var string $cashRound */
/** @var string $taxRate */
/** @var string $backupHeadline */
/** @var string $nightlyHeadline */
$nightlyHeadline = is_string($nightlyHeadline ?? null) ? $nightlyHeadline : '';
$receiptFooter = is_string($receiptFooter ?? null) ? $receiptFooter : '';
/** @var array<string, bool> $catalogs */
/** @var list<array{key: string, label: string}> $stocks */
/** @var string $labelStock */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Settings</h1>
        <p class="muted"><?= $consignmentOnly
            ? 'The store clock, the store take, the sales tax rate, the stickers, how long items stay out, the receipt footer, and a copy of the store.'
            : 'The mall clock, the default booth size, the floor the map draws, the store take, the sales tax rate, the stickers, how long items stay out, the receipt footer, and a copy of the mall.' ?></p>
    </div>
</div>

<div class="card">
    <div class="card-head"><div class="h3">Backup</div></div>
    <div class="card-body stack">
        <p>Last backup you downloaded: <?= View::text($backupHeadline) ?></p>
        <?php if ($nightlyHeadline !== ''): ?>
            <p><?= View::text($nightlyHeadline) ?></p>
        <?php endif; ?>
        <form method="post" action="/<?= View::text($slug) ?>/backup">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <input type="hidden" name="return" value="settings">
            <button class="btn" type="submit">Download backup</button>
        </form>
    </div>
</div>

<form method="post" action="/<?= View::text($slug) ?>/settings" class="card mt-6">
    <div class="card-head"><div class="h3">Money</div></div>
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="settings_part" value="money">
        <div class="field">
            <label for="default_store_take">Store take</label>
            <input class="input" id="default_store_take" name="default_store_take" inputmode="decimal" value="<?= View::text($storeTake) ?>">
            <p class="muted text-sm"><?= $consignmentOnly
                ? 'Percent of a sale this store keeps. 25 is 25%. A different amount saved on a consignor is marked overridden.'
                : 'Percent of a sale this mall keeps. 25 is 25%. A different amount saved on a vendor is marked overridden.' ?></p>
        </div>
        <div class="field">
            <label for="tax_rate">Sales tax</label>
            <input class="input" id="tax_rate" name="tax_rate" inputmode="decimal" value="<?= View::text($taxRate) ?>">
            <p class="muted text-sm"><?= $consignmentOnly
                ? 'Percent added to a taxable sale. 9.997 is 9.997%. Leave this empty and sales are not taxed. A consignor marked tax exempt is charged no tax.'
                : 'Percent added to a taxable sale. 9.997 is 9.997%. Leave this empty and sales are not taxed. A vendor marked tax exempt is charged no tax.' ?></p>
        </div>
        <input type="hidden" name="shop_flags" value="1">
        <div class="field">
            <select class="input" id="operating_mode" name="operating_mode">
                <?php foreach (['both' => 'Consignment and booths', 'consignor' => 'Consignment', 'booth_mall' => 'Booth mall'] as $value => $label): ?>
                    <option value="<?= View::text($value) ?>"<?= $operatingMode === $value ? ' selected' : '' ?>><?= View::text($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted text-sm">Consignment pays a share of each sale. A booth mall charges rent. Both does each.</p>
        </div>
        <div class="field">
            <div class="catalog-switches">
                <label class="switch-row" for="store_stock_enabled">
                    <span class="switch">
                        <input id="store_stock_enabled" name="store_stock_enabled" type="checkbox" value="1"<?= !empty($storeStockEnabled) ? ' checked' : '' ?>>
                        <span class="switch-track" aria-hidden="true"></span>
                        <span class="switch-knob" aria-hidden="true"></span>
                    </span>
                    Store stock
                </label>
                <?php if ($showRent): ?>
                    <label class="switch-row" for="rent_prepaid_from_prior_sales">
                        <span class="switch">
                            <input id="rent_prepaid_from_prior_sales" name="rent_prepaid_from_prior_sales" type="checkbox" value="1"<?= !empty($rentPrepaid) ? ' checked' : '' ?>>
                            <span class="switch-track" aria-hidden="true"></span>
                            <span class="switch-knob" aria-hidden="true"></span>
                        </span>
                        Take rent from prior sales
                    </label>
                <?php endif; ?>
            </div>
            <?php if ($showRent && $cycleFrozen): ?>
                <p>Cycle day <?= View::text((string) $cycleDay) ?></p>
            <?php elseif ($showRent): ?>
                <label for="rent_cycle_day">Cycle day</label>
                <input class="input" id="rent_cycle_day" name="rent_cycle_day" inputmode="numeric" min="1" max="28" value="<?= View::text((string) $cycleDay) ?>" required>
            <?php elseif (!$consignmentOnly): ?>
                <p>This mall does not charge booth rent.</p>
            <?php endif; ?>
            <?php if ($showRent): ?>
                <p><?= View::text($rentSentence) ?></p>
                <?php
                $lateFees = ($lateFees ?? false) === true;
                $lateGrace = is_int($lateGrace ?? null) ? $lateGrace : 0;
                $lateFirst = is_string($lateFirst ?? null) ? $lateFirst : '';
                $lateNext = is_string($lateNext ?? null) ? $lateNext : '';
                ?>
                <div class="catalog-switches">
                    <label class="switch-row" for="late_fees_enabled">
                        <span class="switch">
                            <input id="late_fees_enabled" name="late_fees_enabled" type="checkbox" value="1"<?= $lateFees ? ' checked' : '' ?>>
                            <span class="switch-track" aria-hidden="true"></span>
                            <span class="switch-knob" aria-hidden="true"></span>
                        </span>
                        Late fees
                    </label>
                </div>
                <label for="late_fee_grace_days">Grace days</label>
                <input class="input" id="late_fee_grace_days" name="late_fee_grace_days" inputmode="numeric" min="0" max="28" value="<?= View::text((string) $lateGrace) ?>">
                <p class="muted text-sm">The first fee posts when grace ends. Another fee posts after each further grace period, through the last day of that rent period. Grace of 0 posts a fee each day.</p>
                <label for="late_fee_first">First late fee</label>
                <input class="input" id="late_fee_first" name="late_fee_first" inputmode="decimal" value="<?= View::text($lateFirst) ?>">
                <label for="late_fee_next">Each later late fee</label>
                <input class="input" id="late_fee_next" name="late_fee_next" inputmode="decimal" value="<?= View::text($lateNext) ?>">
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="cash_round_minor">Round cash sales to</label>
            <select class="select" id="cash_round_minor" name="cash_round_minor">
                <?php
                $roundNow = (string) ($cashRound ?? '0');
                $roundChoices = [
                    '0' => 'No cash rounding',
                    '1' => 'Nearest cent ($0.01)',
                    '5' => 'Nearest nickel ($0.05)',
                    '10' => 'Nearest dime ($0.10)',
                    '25' => 'Nearest quarter ($0.25)',
                ];
                foreach ($roundChoices as $value => $label):
                    $step = (string) $value;
                    ?>
                    <option value="<?= View::text($step) ?>"<?= $roundNow === $step ? ' selected' : '' ?>><?= View::text($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted text-sm">Cash taken on a sale rounds to this step. The round amount shows on the register and on the receipt. Card, checks, and stored value stay exact.</p>
        </div>
    </div>
    <div class="card-foot">
        <button class="btn btn-primary" type="submit">Save money</button>
    </div>
</form>

<form method="post" action="/<?= View::text($slug) ?>/settings" class="card mt-6">
    <div class="card-head"><div class="h3">Building</div></div>
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="settings_part" value="building">
        <div class="field">
            <label for="time_zone">Time zone</label>
            <select class="select" id="time_zone" name="time_zone" required>
                <option value="">Choose a time zone</option>
                <?php foreach ($timeZones as $zone): ?>
                    <option value="<?= View::text($zone) ?>"<?= $zone === $timeZone ? ' selected' : '' ?>><?= View::text(ZoneName::label($zone)) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted text-sm"><?= $consignmentOnly
                ? 'This is the clock the store uses for today and receipts.'
                : 'This is the clock the mall uses for today, rent, and receipts.' ?></p>
        </div>
        <?php if ($consignmentOnly): ?>
            <input type="hidden" name="booth_width_ft" value="<?= View::text((string) $boothWidth) ?>">
            <input type="hidden" name="booth_depth_ft" value="<?= View::text((string) $boothDepth) ?>">
            <input type="hidden" name="floor_width_ft" value="<?= View::text((string) $floorWidth) ?>">
            <input type="hidden" name="floor_depth_ft" value="<?= View::text((string) $floorDepth) ?>">
        <?php else: ?>
        <div class="field">
            <label for="booth_width_ft">Default width (ft)</label>
            <input class="input" id="booth_width_ft" name="booth_width_ft" inputmode="numeric" value="<?= View::text($boothWidth) ?>" required>
            <p class="muted text-sm">A booth with no size of its own uses this width.</p>
        </div>
        <div class="field">
            <label for="booth_depth_ft">Default depth (ft)</label>
            <input class="input" id="booth_depth_ft" name="booth_depth_ft" inputmode="numeric" value="<?= View::text($boothDepth) ?>" required>
            <p class="muted text-sm">A booth with no size of its own uses this depth.</p>
        </div>
        <div class="field">
            <label for="floor_width_ft">Floor width (ft)</label>
            <input class="input" id="floor_width_ft" name="floor_width_ft" inputmode="numeric" value="<?= View::text($floorWidth) ?>" required>
            <p class="muted text-sm">The map draws the floor this wide.</p>
        </div>
        <div class="field">
            <label for="floor_depth_ft">Floor depth (ft)</label>
            <input class="input" id="floor_depth_ft" name="floor_depth_ft" inputmode="numeric" value="<?= View::text($floorDepth) ?>" required>
            <p class="muted text-sm">The map draws the floor this deep.</p>
        </div>
        <?php endif; ?>
        <div class="field">
            <label for="label_stock">Stickers we use</label>
            <select class="select" id="label_stock" name="label_stock">
                <?php foreach ($stocks as $stock): ?>
                    <option value="<?= View::text($stock['key']) ?>"<?= $stock['key'] === $labelStock ? ' selected' : '' ?>><?= View::text($stock['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted text-sm">Print stickers starts on this sheet or tag. A print can still pick another.</p>
        </div>
        <div class="field">
            <p class="muted text-sm">Item fields. Turn one off and it leaves the intake form, the register, and the menu. A value already stored on an item stays until the field is turned back on.</p>
            <div class="catalog-switches">
            <?php foreach ([
                'use_brand' => 'Brand',
                'use_color' => 'Color',
                'use_category' => 'Category',
                'use_size' => 'Size',
                'use_condition' => 'Condition',
                'use_department' => 'Department',
            ] as $flag => $label): ?>
                <label class="switch-row" for="<?= View::text($flag) ?>">
                    <span class="switch">
                        <input id="<?= View::text($flag) ?>" name="<?= View::text($flag) ?>" type="checkbox" value="1"<?= !empty($catalogs[$flag]) ? ' checked' : '' ?>>
                        <span class="switch-track" aria-hidden="true"></span>
                        <span class="switch-knob" aria-hidden="true"></span>
                    </span>
                    <?= View::text($label) ?>
                </label>
            <?php endforeach; ?>
            </div>
        </div>
        <?php if (!$boothMall && !empty($consignmentEnabled)): ?>
        <div class="field">
            <label for="floor_days">Days on the floor</label>
            <input class="input" id="floor_days" name="floor_days" inputmode="numeric" value="<?= View::text($floorDays) ?>">
            <p class="muted text-sm">How long a new consigned item stays before it expires. Leave this empty and consigned items do not expire.</p>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-foot">
        <button class="btn btn-primary" type="submit">Save building</button>
    </div>
</form>

<form method="post" action="/<?= View::text($slug) ?>/settings" class="card mt-6">
    <div class="card-head"><div class="h3">Receipt</div></div>
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="settings_part" value="receipt">
        <div class="field">
            <label for="receipt_footer">Footer</label>
            <textarea class="textarea" id="receipt_footer" name="receipt_footer" maxlength="400" rows="3"><?= View::text($receiptFooter) ?></textarea>
            <p class="muted text-sm"><?= $consignmentOnly
                ? 'Printed at the bottom of a sale receipt. Leave this empty and those receipts have no footer.'
                : 'Printed at the bottom of a sale receipt and a rent receipt. Leave this empty and those receipts have no footer.' ?></p>
        </div>
        <div class="field">
            <label for="receipt_mail_via">Mail Server</label>
            <select class="input" id="receipt_mail_via" name="receipt_mail_via">
                <?php foreach (['server' => 'Mail Server', 'gmail' => $consignmentOnly ? 'This store\'s Gmail' : 'This mall\'s Gmail'] as $value => $label): ?>
                    <option value="<?= View::text($value) ?>"<?= ($receiptMailVia ?? 'server') === $value ? ' selected' : '' ?>><?= View::text($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted text-sm">This setting is used for receipts, password resets, and other system email. Mail Server uses the mailbox configured for this site. Gmail sends through smtp.gmail.com as a @gmail.com or @googlemail.com address, with an app password.</p>
        </div>
        <div class="field">
            <label for="gmail_address">Gmail address</label>
            <input class="input" id="gmail_address" name="gmail_address" type="email" maxlength="200" value="<?= View::text(is_string($gmailAddress ?? null) ? $gmailAddress : '') ?>">
        </div>
        <div class="field">
            <label for="gmail_app_password">Gmail app password</label>
            <?php View::passwordField(id: 'gmail_app_password', name: 'gmail_app_password', autocomplete: 'new-password', value: ''); ?>
            <p class="muted text-sm"><?= !empty($gmailPasswordSaved) ? 'An app password is saved. Leave this blank to keep it.' : 'Create an app password in the Google account. Spaces are ignored.' ?></p>
        </div>
    </div>
    <div class="card-foot">
        <button class="btn btn-primary" type="submit">Save receipt</button>
    </div>
</form>
