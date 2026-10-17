<?php

declare(strict_types=1);

use PerfectConsign\Feature\Mall\ZoneName;
use PerfectConsign\Feature\Rent\RentCycle;
use PerfectConsign\Shared\Http\View;

$boothRentals = ($boothRentals ?? true) === true;
$cycleDay = is_int($cycleDay ?? null) && $cycleDay >= 1 && $cycleDay <= 28 ? $cycleDay : 1;
$cycleFrozen = ($cycleFrozen ?? false) === true;
$rentSentence = $boothRentals ? (new RentCycle($cycleDay))->sentence(!empty($rentPrepaid)) : '';

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
/** @var array<string, bool> $catalogs */
/** @var list<array{key: string, label: string}> $stocks */
/** @var string $labelStock */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Settings</h1>
        <p class="muted">The mall clock, the default booth size, the floor the map draws, the store take, the sales tax rate, the stickers, how long items stay out, and a copy of the mall.</p>
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
            <p class="muted text-sm">Percent of a sale this mall keeps. 25 is 25%. A different amount saved on a vendor is marked overridden.</p>
        </div>
        <div class="field">
            <label for="tax_rate">Sales tax</label>
            <input class="input" id="tax_rate" name="tax_rate" inputmode="decimal" value="<?= View::text($taxRate) ?>">
            <p class="muted text-sm">Percent added to a taxable sale. 9.997 is 9.997%. Leave this empty and sales are not taxed. A vendor marked tax exempt is charged no tax.</p>
        </div>
        <div class="field">
            <label for="operating_mode">How this mall pays</label>
            <select class="input" id="operating_mode" name="operating_mode">
                <?php foreach (['both' => 'Consignment and booths', 'consignor' => 'Consignment', 'booth_mall' => 'Booth mall'] as $value => $label): ?>
                    <option value="<?= View::text($value) ?>"<?= ($operatingMode ?? '') === $value ? ' selected' : '' ?>><?= View::text($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted text-sm">Consignment pays a share of each sale. A booth mall charges rent. Both does each.</p>
        </div>
        <div class="field">
            <p class="muted text-sm">Booth rent</p>
            <div class="catalog-switches">
                <label class="switch-row" for="booth_rentals_enabled">
                    <span class="switch">
                        <input id="booth_rentals_enabled" name="booth_rentals_enabled" type="checkbox" value="1"<?= $boothRentals ? ' checked' : '' ?>>
                        <span class="switch-track" aria-hidden="true"></span>
                        <span class="switch-knob" aria-hidden="true"></span>
                    </span>
                    Booth rentals
                </label>
                <?php if ($boothRentals): ?>
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
            <?php if ($boothRentals && $cycleFrozen): ?>
                <p>Cycle day <?= View::text((string) $cycleDay) ?></p>
            <?php elseif ($boothRentals): ?>
                <label for="rent_cycle_day">Cycle day</label>
                <input class="input" id="rent_cycle_day" name="rent_cycle_day" inputmode="numeric" min="1" max="28" value="<?= View::text((string) $cycleDay) ?>" required>
            <?php else: ?>
                <p>This mall does not charge booth rent.</p>
            <?php endif; ?>
            <?php if ($boothRentals): ?>
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
                <p class="muted text-sm">Counted from the day the rent period starts. The fee posts on the day grace ends, once for each unpaid period.</p>
                <label for="late_fee_first">First late period</label>
                <input class="input" id="late_fee_first" name="late_fee_first" inputmode="decimal" value="<?= View::text($lateFirst) ?>">
                <label for="late_fee_next">Each later late period</label>
                <input class="input" id="late_fee_next" name="late_fee_next" inputmode="decimal" value="<?= View::text($lateNext) ?>">
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="cash_round_nickel">
                <input id="cash_round_nickel" name="cash_round_nickel" type="checkbox" value="1"<?= ($cashRound ?? '') === '1' ? ' checked' : '' ?>>
                Round cash sales to the nearest nickel
            </label>
            <p class="muted text-sm">Cash taken on a sale rounds to a nickel. The round amount shows on the register and on the receipt. Card, checks, and stored value stay exact.</p>
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
            <p class="muted text-sm">This is the clock the mall uses for today, rent, and receipts.</p>
        </div>
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
        <div class="field">
            <label for="floor_days">Days on the floor</label>
            <input class="input" id="floor_days" name="floor_days" inputmode="numeric" value="<?= View::text($floorDays) ?>">
            <p class="muted text-sm">How long a new item stays before it expires. Leave this empty and items do not expire. A consignment store sets it. A vendor mall leaves it empty.</p>
        </div>
    </div>
    <div class="card-foot">
        <button class="btn btn-primary" type="submit">Save building</button>
    </div>
</form>
