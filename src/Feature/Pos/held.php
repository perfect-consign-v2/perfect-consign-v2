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
/** @var list<array{id: string, customer: string, pieces: int, deposits: int, balance: int, last: string}> $layaways */

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
        <?php if ($layaway && $layaways === []): ?>
            <p class="muted">No layaways.</p>
        <?php elseif (!$layaway && $rows === []): ?>
            <p class="muted">No suspended sales.</p>
        <?php elseif ($layaway): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Pieces</th>
                            <th>Deposits</th>
                            <th>Balance</th>
                            <th>Last deposit</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($layaways as $held): ?>
                            <tr>
                                <td><?= View::text($held['customer']) ?></td>
                                <td><?= View::text((string) $held['pieces']) ?></td>
                                <td><?= View::text(View::money($held['deposits'])) ?></td>
                                <td><?= View::text(View::money($held['balance'])) ?></td>
                                <td><?= View::text($held['last']) ?></td>
                                <td>
                                    <div class="row gap-2">
                                        <a class="btn" href="/<?= View::text($slug) ?>/pos/layaway/<?= View::text($held['id']) ?>">Receipt</a>
                                        <form method="post" action="/<?= View::text($slug) ?>/pos/recall">
                                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                            <input type="hidden" name="ticket_id" value="<?= View::text($held['id']) ?>">
                                            <button class="btn btn-primary" type="submit">Recall</button>
                                        </form>
                                        <form method="post" action="/<?= View::text($slug) ?>/pos/layaway/cancel">
                                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                            <input type="hidden" name="ticket_id" value="<?= View::text($held['id']) ?>">
                                            <button class="btn" type="submit">Cancel</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
                                    <form method="post" action="/<?= View::text($slug) ?>/pos/recall">
                                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                        <input type="hidden" name="ticket_id" value="<?= View::text($held['id']) ?>">
                                        <button class="btn btn-primary" type="submit">Recall</button>
                                    </form>
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
