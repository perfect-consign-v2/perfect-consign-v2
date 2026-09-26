<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $periods */
?>
<h1>Year-end close</h1>
<table>
    <thead><tr><th>From</th><th>To</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($periods as $period): ?>
        <tr>
            <td><?= View::text($period['starts_on']) ?></td>
            <td><?= View::text($period['ends_on']) ?></td>
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
