<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $timeZone */
/** @var int $boothWidth */
/** @var int $boothDepth */
/** @var int $floorWidth */
/** @var int $floorDepth */
/** @var string $storeTake */
/** @var string $floorDays */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Settings</h1>
        <p class="muted">The mall clock, the default booth size, the floor the map draws, the store take, and how long pieces stay out.</p>
    </div>
</div>

<form method="post" action="/<?= View::text($slug) ?>/settings" class="grid form-split" style="grid-template-columns: 2fr 1fr">
    <div class="card">
        <div class="card-head"><div class="h3">Mall</div></div>
        <div class="card-body grid cols-2">
            <div class="field" style="grid-column:1/-1">
                <label for="time_zone">Time zone</label>
                <input class="input" id="time_zone" name="time_zone" value="<?= View::text($timeZone) ?>" required>
            </div>
            <div class="field">
                <label for="booth_width_ft">Default width (ft)</label>
                <input class="input" id="booth_width_ft" name="booth_width_ft" inputmode="numeric" value="<?= View::text($boothWidth) ?>" required>
            </div>
            <div class="field">
                <label for="booth_depth_ft">Default depth (ft)</label>
                <input class="input" id="booth_depth_ft" name="booth_depth_ft" inputmode="numeric" value="<?= View::text($boothDepth) ?>" required>
            </div>
            <div class="field">
                <label for="floor_width_ft">Floor width (ft)</label>
                <input class="input" id="floor_width_ft" name="floor_width_ft" inputmode="numeric" value="<?= View::text($floorWidth) ?>" required>
            </div>
            <div class="field">
                <label for="floor_depth_ft">Floor depth (ft)</label>
                <input class="input" id="floor_depth_ft" name="floor_depth_ft" inputmode="numeric" value="<?= View::text($floorDepth) ?>" required>
            </div>
            <div class="field" style="grid-column:1/-1">
                <label for="default_store_take">Store take</label>
                <input class="input" id="default_store_take" name="default_store_take" inputmode="decimal" value="<?= View::text($storeTake) ?>">
                <p class="muted text-sm">Percent of a sale this mall keeps. 25 is 25%. A different amount saved on a vendor is marked overridden.</p>
            </div>
            <div class="field" style="grid-column:1/-1">
                <label for="floor_days">Days on the floor</label>
                <input class="input" id="floor_days" name="floor_days" inputmode="numeric" value="<?= View::text($floorDays) ?>">
                <p class="muted text-sm">How long a new piece stays before it expires. Leave this empty and pieces do not expire. A consignment store sets it. A vendor mall leaves it empty.</p>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="h3">Notes</div></div>
        <div class="card-body">
            <p class="muted text-sm">Today, rent months, and the posting date start from this time zone. A booth with no size uses the width and depth here. The floor starts at 120 by 90 feet. Store take is this mall's default split. Days on the floor is the consignment expire default.</p>
        </div>
        <div class="card-foot row between">
            <a class="btn btn-ghost" href="/<?= View::text($slug) ?>/book">Cancel</a>
            <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> Save changes</button>
        </div>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>
