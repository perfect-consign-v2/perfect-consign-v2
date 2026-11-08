<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $sellerList */
/** @var string $sellerLabel */
/** @var list<array{id: string, name: string, kind: string, roles: string}> $parties */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Parties</h1>
        <p class="muted">People and organizations this store does business with. Roles (<?= View::text(strtolower($sellerLabel)) ?>, customer, wait list) hang off each party.</p>
    </div>
    <a class="btn btn-primary" href="<?= View::text($sellerList) ?>"><?= View::text($sellerLabel) ?></a>
</div>
<?php if ($parties === []): ?>
    <div class="card mt-6">
        <div class="empty">
            <p>No parties yet. Add a <?= View::text(strtolower($sellerLabel === 'Consignors' ? 'consignor' : 'vendor')) ?> or a customer.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card table-wrap list-dt-wrap mt-6">
        <?php
        ListTable::open(['id' => 'parties', 'export' => 'parties']);
        ListTable::head([
            ['label' => 'Name'],
            ['label' => 'Kind'],
            ['label' => 'Roles'],
        ]);
        ?>
            <tbody>
            <?php foreach ($parties as $party): ?>
                <tr>
                    <td><a href="/<?= View::text($slug) ?>/parties/<?= View::text($party['id']) ?>"><?= View::text($party['name']) ?></a></td>
                    <td><?= View::text($party['kind']) ?></td>
                    <td><?= View::text($party['roles']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php ListTable::assets(); ?>
