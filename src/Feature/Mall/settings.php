<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $timeZone */
/** @var int $boothWidth */
/** @var int $boothDepth */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Settings</h1>
        <p class="muted">The mall clock and the booth size used when a booth has none of its own.</p>
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
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="h3">Notes</div></div>
        <div class="card-body">
            <p class="muted text-sm">Today, rent months, and the posting date start from this time zone. A booth with no size uses the width and depth here. 10 by 10 is the starting size.</p>
        </div>
        <div class="card-foot row between">
            <a class="btn btn-ghost" href="/<?= View::text($slug) ?>/book">Cancel</a>
            <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> Save changes</button>
        </div>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>
