<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $periods */
?>
<h1>Close the year</h1>
<div class="list-dt-wrap">
<?php
ListTable::open(['id' => 'close-periods', 'export' => 'close-periods']);
ListTable::head([
    ['label' => 'From'],
    ['label' => 'To'],
    ['label' => 'Status'],
    ['label' => '', 'actions' => true],
]);
?>
    <tbody>
    <?php foreach ($periods as $period): ?>
        <tr>
            <td><?= View::date($period['starts_on']) ?></td>
            <td><?= View::date($period['ends_on']) ?></td>
            <td><?= View::text($period['status']) ?></td>
            <td>
                <?php if ($period['status'] === 'open'): ?>
                    <form method="post" action="/<?= View::text($slug) ?>/close">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <input type="hidden" name="period_id" value="<?= View::text($period['id']) ?>">
                        <input type="hidden" name="ends_on" value="<?= View::text($period['ends_on']) ?>">
                        <button type="submit">Close</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php ListTable::assets(); ?>
