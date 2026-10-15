<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $base */
/** @var string|null $error */
/** @var list<array{id: string, name: string, scope: string, kind: string, threshold: int, deal_minor: int, funded_by: string, starts_on: string, ends_on: string, scope_label: string, ended: bool}> $offers */

$scopeName = static function (string $scope, string $label): string {
    if ($scope === 'store') {
        return 'Whole store';
    }
    if ($scope === 'vendor') {
        return 'Vendor ' . $label;
    }

    return 'Item ' . $label;
};
$dealName = static function (string $kind, int $threshold, int $minor): string {
    $price = View::money($minor);
    if ($kind === 'unit_break') {
        return (string) $threshold . ' or more at ' . $price . ' each';
    }

    return (string) $threshold . ' for ' . $price;
};
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Deals</h1>
        <p class="muted">Quantity prices. The tag price stays on the item.</p>
    </div>
    <a class="btn btn-primary" href="<?= View::text($base) ?>/new">New deal</a>
</div>
<?php if ($offers === []): ?>
    <div class="card">
        <div class="empty">
            <p>No deals yet.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-wrap mt-6">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Applies to</th>
                    <th>Deal</th>
                    <th>Pays</th>
                    <th>Dates</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offers as $offer): ?>
                    <tr>
                        <td>
                            <a href="<?= View::text($base) ?>/<?= View::text($offer['id']) ?>"><?= View::text($offer['name']) ?></a>
                            <?php if ($offer['ended']): ?><span class="badge">Ended</span><?php endif; ?>
                        </td>
                        <td><?= View::text($scopeName($offer['scope'], $offer['scope_label'])) ?></td>
                        <td><?= View::text($dealName($offer['kind'], $offer['threshold'], $offer['deal_minor'])) ?></td>
                        <td><?= View::text($offer['funded_by'] === 'store' ? 'Store' : 'Vendor') ?></td>
                        <td><?= View::text(View::date($offer['starts_on'])) ?><?php if ($offer['ends_on'] !== ''): ?> – <?= View::text(View::date($offer['ends_on'])) ?><?php endif; ?></td>
                        <td>
                            <?php if (!$offer['ended']): ?>
                                <form method="post" action="<?= View::text($base) ?>/<?= View::text($offer['id']) ?>/end">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <button class="btn btn-sm" type="submit">End</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
