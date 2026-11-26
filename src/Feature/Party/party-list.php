<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $sellerList */
/** @var string $sellerLabel */
/** @var int $partyCount */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Parties</h1>
        <p class="muted">People and organizations this store does business with. Roles (<?= View::text(strtolower($sellerLabel)) ?>, customer, wait list) hang off each party.</p>
    </div>
    <a class="btn btn-primary" href="<?= View::text($sellerList) ?>"><?= View::text($sellerLabel) ?></a>
</div>
<?php if ($partyCount === 0): ?>
    <div class="card mt-6">
        <div class="empty">
            <p>No parties yet. Add a <?= View::text(strtolower($sellerLabel === 'Consignors' ? 'consignor' : 'vendor')) ?> or a customer.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card table-wrap list-dt-wrap mt-6">
        <?php
        ListTable::open([
            'id' => 'parties',
            'export' => 'parties',
            'server' => true,
            'rows' => '/' . $slug . '/parties/rows',
        ]);
        ListTable::head([
            ['label' => 'Name', 'key' => 'name'],
            ['label' => 'Kind', 'key' => 'kind'],
            ['label' => 'Roles', 'key' => 'roles'],
        ]);
        ?>
            <tbody></tbody>
        </table>
    </div>
<?php endif; ?>
<?php ListTable::assets(); ?>
