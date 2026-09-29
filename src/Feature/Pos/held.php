<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $csrf */
/** @var string $templateDirectory */
/** @var string $heldStatus */
/** @var array{
 *     parked: list<array{id: string, pieces: int, sold: int, status: string}>
 * } $cart */

$layaway = $heldStatus === 'layaway';
$rows = [];
foreach ($cart['parked'] as $held) {
    if ($held['status'] === $heldStatus) {
        $rows[] = $held;
    }
}
require $templateDirectory . '/Feature/Pos/register-nav.php';
?>
<div class="card">
    <div class="card-head">
        <div class="h3"><?= $layaway ? 'Layaways' : 'Suspended sales' ?></div>
        <a class="btn" href="/<?= View::text($slug) ?>/pos">Back to POS</a>
    </div>
    <div class="card-body">
        <?php if ($rows === []): ?>
            <p class="muted"><?= $layaway ? 'No layaways.' : 'No suspended sales.' ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Pieces</th>
                            <th>Sold</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $held): ?>
                            <tr>
                                <td><?= View::text((string) $held['pieces']) ?></td>
                                <td><?= View::text(View::money(RowValue::integer($held['sold']))) ?></td>
                                <td>
                                    <div class="row gap-2">
                                        <form method="post" action="/<?= View::text($slug) ?>/pos/recall">
                                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                            <input type="hidden" name="ticket_id" value="<?= View::text($held['id']) ?>">
                                            <button class="btn btn-primary" type="submit">Recall</button>
                                        </form>
                                        <?php if ($layaway): ?>
                                            <form method="post" action="/<?= View::text($slug) ?>/pos/layaway/cancel">
                                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                                <input type="hidden" name="ticket_id" value="<?= View::text($held['id']) ?>">
                                                <button class="btn" type="submit">Cancel</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require $templateDirectory . '/Feature/Pos/register-ui.php'; ?>
