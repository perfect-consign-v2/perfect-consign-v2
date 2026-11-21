<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\NavClock;

/** @var string $navZone */

$navZone = is_string($navZone ?? null) ? $navZone : '';
if ($navZone === '') {
    return;
}
$face = (new NavClock($navZone))->face();
?>
<time class="topbar-clock" datetime="<?= View::text($face['instant']) ?>" data-mall-clock data-time-zone="<?= View::text($navZone) ?>" translate="no">
    <span class="topbar-clock-date" data-clock-date><?= View::text($face['date']) ?></span>
    <span class="topbar-clock-time" data-clock-time><?= View::text($face['time']) ?></span>
</time>
