<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $title */
/** @var string $sentence */
/** @var string $label */
/** @var string|null $error */
/** @var string|null $empty */
/** @var list<string> $headers */
/** @var list<list<string>> $lines */
/** @var list<string>|null $total */
/** @var string $download */
/** @var string $mode */
/** @var string $group */
/** @var string $from */
/** @var string $to */

$base = '/' . $slug . '/reports';
$sheet = str_contains($download, 'sales.csv') ? 'sales' : '';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($title) ?></h1>
        <?php if ($label !== ''): ?>
            <p class="muted"><?= View::text($label) ?></p>
        <?php endif; ?>
    </div>
    <a class="btn" href="<?= View::text($base) ?>/<?= View::text($download) ?>">Download CSV</a>
</div>
<?php if ($sentence !== ''): ?>
    <p><?= View::text($sentence) ?></p>
<?php endif; ?>
<?php if ($mode === 'hour'): ?>
    <p>
        <a href="<?= View::text($base) ?>/sales?group=hour&amp;range=today">Today</a>
        · <a href="<?= View::text($base) ?>/sales?group=hour&amp;range=yesterday">Yesterday</a>
    </p>
    <form method="get" action="<?= View::text($base) ?>/sales">
        <input type="hidden" name="group" value="hour">
        <label for="report-day">Day</label>
        <input id="report-day" type="date" name="day" value="<?= View::text($from) ?>">
        <button type="submit">Show</button>
    </form>
<?php elseif ($mode === 'range'): ?>
    <?php
    $csv = explode('?', $download, 2)[0];
    $page = str_ends_with($csv, '.csv') ? substr($csv, 0, -4) : $csv;
    ?>
    <p>
        <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month'] as $key => $name): ?>
            <?php
            $href = $base . '/' . $page . '?range=' . $key;
            if ($sheet === 'sales') {
                $href .= '&group=' . rawurlencode($group === '' ? 'day' : $group);
            }
            ?>
            <a href="<?= View::text($href) ?>"><?= View::text($name) ?></a>
            <?= $key === 'last_month' ? '' : ' · ' ?>
        <?php endforeach; ?>
    </p>
    <form method="get" action="<?= View::text($base . '/' . $page) ?>">
        <?php if ($sheet === 'sales'): ?>
            <input type="hidden" name="group" value="<?= View::text($group === '' ? 'day' : $group) ?>">
        <?php endif; ?>
        <div class="report-span">
            <div>
                <label for="report-from">From</label>
                <input id="report-from" type="date" name="from" value="<?= View::text($from) ?>">
            </div>
            <div>
                <label for="report-to">To</label>
                <input id="report-to" type="date" name="to" value="<?= View::text($to) ?>">
            </div>
        </div>
        <button type="submit">Show</button>
    </form>
<?php endif; ?>
<?php if ($error !== null && $error !== ''): ?>
    <p class="error"><?= View::text($error) ?></p>
<?php elseif ($empty !== null && $empty !== ''): ?>
    <div class="card mt-6">
        <div class="empty">
            <p><?= View::text($empty) ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="card table-wrap mt-6">
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $header): ?>
                        <th><?= View::text($header) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <?php foreach ($line as $cell): ?>
                            <td><?= View::text($cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($total !== null): ?>
                <tfoot>
                    <tr>
                        <?php foreach ($total as $cell): ?>
                            <th><?= View::text($cell) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
<?php endif; ?>
